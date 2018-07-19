<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Логика скрипта:
 * пройтись по сделкам в кастомерс, выделив цифровую часть из имен сделок
 * проверить наличие аккаунта с таким ID
 * записать сделки, по которым было найдено совпадение, в файл для адейта
 * пройтись по остальным сделкам, повторив процедуру по именам прикрепленных компаний
 *
 **/

$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];
$curl = $container['curl'];

$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error in customers\n");
}

$params = new \Cli\Params\CLI_Params();

try {
	$params
		->add(new Optional('offset', 'o', 'limit_offset parameter', Param::TYPE_INT))
		->add(new Optional('count', 'c', 'count of leads getting by one request', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp/amol)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($params->get_info());
	die;
}

$files_path = $params->get('dir');
$logger->log($files_path);
$offset = (int)$params->get('offset');
$count = (int)$params->get('count');

$offset = ($offset > 0) ? $offset : 0;
$count = ($count > 0) ? $count : 500;
$limit_offset = $offset;

// Формирование данных для апдейта по названиям сделок
$chunk_count = 250;    // Количество id сущностей, передаваемых массивом вторым аргументом в $api->find(по пятьсот не отдает)
$leads_result = TRUE;
$i = 0;

$check_by_company = [];
$final_other_leads = [];
$total_count = 0;

$update_file = $files_path . '/update-data.txt';
$will_not_update = $files_path . '/willnot-update.json';

while ($leads_result) {
	$logger->log("getting leads... OFFSET: {$limit_offset}");
	$leads_result = $api->find('leads', ['limit_rows' => $count, 'limit_offset' => $limit_offset]);
	$limit_offset += $count;
	if (!$leads_result) {
		$logger->log('0 leads received');
		break;
	}
	$logger->log('Checking ' . count($leads_result) . ' leads...');

	$check_names_result = check_names($leads_result);
	$leads_to_check = $check_names_result['elements_to_check'];
	$numbers_from_name = $check_names_result['numbers_from_name'];
	$check_by_company = array_merge($check_by_company, $check_names_result['elements_ids']);

	if (count($numbers_from_name) > 0) {
		$accounts_ids = find_accounts($numbers_from_name);
		$leads_for_update = array_intersect($leads_to_check, $accounts_ids);
		$total_count += count($leads_for_update);
		$logger->log($total_count . ' leads for update');
		foreach ($leads_for_update as $lead_id => $acc_id) {
			$data = [$lead_id => $acc_id];
			write_to_file($update_file, $data);
		}
		$other_leads = array_diff($leads_to_check, $leads_for_update);
		$final_other_leads += $other_leads;
	}
}

$final_check_by_company = array_merge($check_by_company, array_keys($final_other_leads));
$logger->log(count($final_check_by_company) . ' leads will be checked by linked companies\' names');

// Формирование данных для апдейта по названиям прикрепленных компаний
$companies_id = [];
$with_companies = [];
$without_companies = [];
$other_leads = [];
$final_other_leads = [];
$total_leads_for_update = [];

if (count($final_check_by_company) > 0) {
	$data_chunks = array_chunk($final_check_by_company, 250);
	foreach ($data_chunks as $data) {
		$leads_result = $api->find('leads', ['id' => $data]);

		foreach ($leads_result as $lead) {
			if ($comp_id = $lead['linked_company_id']) {
				$companies_id[] = $comp_id;
				$with_companies[$comp_id][] = (int)$lead['id']; // Массив массивов компания => сделки
			} else {
				$without_companies[] = $lead['id']; // id сделок без компаний
				write_to_file($will_not_update, $lead['id']);
			}
		}
	}

	$logger->log(count($companies_id) . ' leads found with linked companies');
	$logger->log(count($without_companies) . ' leads without linked companies logged to not update file');
	$logger->log('checking ' . count($companies_id) . ' leads by companies names');

	$companies_id = array_unique($companies_id);
	$comp_data_chunks = array_chunk($companies_id, 250);

	foreach ($comp_data_chunks as $comp_data) {
		$comp_result = $api->find('companies', ['id' => $comp_data]);
		if (count($comp_result) > 0) {

			$check_names_result = check_names($comp_result);
			$comp_to_check = $check_names_result['elements_to_check'];
			$numbers_from_name = $check_names_result['numbers_from_name'];

			if (count($numbers_from_name) > 0) {
				$accounts_ids = find_accounts($numbers_from_name);
				$checked_comp = array_intersect($comp_to_check, $accounts_ids); // Компании, в имени которых есть ID существующего аккаунта
				$leads_for_update = array_intersect_key($with_companies, $checked_comp); // Массив сделок для апдейта с ключом - ID привязанной компании
				$other_leads = array_diff_key($with_companies, $checked_comp);
				$added_count = 0;
				$others_count = 0;

				foreach ($leads_for_update as $comp_id => $leads) {
					$account_id = $checked_comp[$comp_id];
					foreach ($leads as $lead_id) {
						$update_data = [$lead_id => $account_id];
						write_to_file($update_file, $update_data);
						$added_count++;
					}
				}
				$logger->log($added_count . ' leads added to update file');

				foreach ($other_leads as $comp_id => $leads) {
					foreach ($leads as $lead_id) {
						write_to_file($will_not_update, $lead_id);
						$others_count++;
					}
				}
				$logger->log($others_count . ' leads added to not updated file');
			}
		} else {
			continue;
		}
	}
}

function check_names($elements) {
	$result = [];
	$elements_to_check = [];
	$numbers_from_name = [];
	$elements_ids = [];
	foreach ($elements as $element) {
		$element_name = $element['name'];
		$element_id = (int)$element['id'];
		$number_from_name = preg_replace("/[^0-9]/", '', $element_name);  // получение числовой части из названия сделки
		if ($number_from_name) {
			$elements_to_check[$element_id] = $number_from_name;
			$numbers_from_name[] = $number_from_name;  // массив чисел, полученных из названий, для проверки на существование аккаунта
		} else {
			$elements_ids[] = $element_id;
		}
		$result['elements_to_check'] = $elements_to_check;
		$result['numbers_from_name'] = $numbers_from_name;
		$result['elements_ids'] = $elements_ids;
	}
	return $result;
}

function find_accounts($numbers) {
	global $db;
	$accounts_ids = [];
	$numbers = array_map('intval', $numbers);
	$in = '(' . implode(',', $numbers) . ')';
	$sql = "SELECT IBLOCK_ELEMENT_ID FROM b_iblock_element_prop_s4 WHERE IBLOCK_ELEMENT_ID IN " . $in;
	$resource = $db->query($sql);
	while ($row = $resource->fetch(FALSE)) {
		$accounts_ids[] = $row['IBLOCK_ELEMENT_ID'];  // массив id аккаунтов, найденных в таблице
	}
	return $accounts_ids;
}

function write_to_file($path, $data, $encode = TRUE) {
	if ($encode) {
		$data = json_encode($data);
	}
	file_put_contents($path, $data . "\n", FILE_APPEND);
}
