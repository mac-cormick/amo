<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Libs\Account\Interfaces\Custom_Fields;

/**
 * Логика скрипта:
 * получить группу дублей-контактов из сформированного файла
 * пройтись по полям, сформировав массив для передачи в /ajax/contacts/merge/save
 * значения полей, способных иметь несколько значений(Phone, Email, ...), объединяются в рез. элементе
 * значения полей, способных иметь только одно значение, берутся у последнего обновленного элемента из группы дублей
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
	$params
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp/amol)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');

$doubles_file = $files_path . '/cus_contacts_doubles.txt';
$unmerged_file = $files_path . '/cus_contacts_unmerged.txt';
$errors_file = $files_path . '/cus_contacts_errors.txt';
$backup_file = $files_path . '/cus_contacts_backup.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error in customers\n");
}

// Распределение полей контактов на 2 типа: - одно значение, - несколько значений
$account_info = $api->get_account();
$contacts_custom_fields_info = $account_info['account']['custom_fields']['contacts'];
$standard_fields = [];
$multi_values_fields = [];

foreach ($contacts_custom_fields_info as $field) {
	$field_type = $field['type_id'];
	$id = $field['id'];
	switch ($field_type) {
		case Custom_Fields::FIELD_TYPE_TEXT:
		case Custom_Fields::FIELD_TYPE_NUMERIC:
		case Custom_Fields::FIELD_TYPE_CHECKBOX:
		case Custom_Fields::FIELD_TYPE_SELECT:
			$standard_fields[] = $id;
			break;
		case Custom_Fields::FIELD_TYPE_MULTITEXT:
			$multi_values_fields[] = $id;
			break;
	}
}

$logger->log('Starting script execution...');
$logger->separator();

$handle = fopen($doubles_file, 'rt');
if (!$handle) {
	$logger->error('Opening file error');
	die();
}

// Читаем файл построчно. Каждая строка - массив айдишников группы дублей контактов
$line_number = 0;
$line_get_result = TRUE;
while ($line_get_result) {
	$line_number++;
	$merge_data = fgets($handle);
	if (!$merge_data) {
		if (feof($handle)) {
			fclose($update_file_open);
			$logger->log('End of file');
			$line_get_result = FALSE;
			break;
		} else {
			$logger->error('Reading error. Line number: '  . $line_number);
		}
	}
	$merge_data = json_decode($merge_data, TRUE);
	$logger->separator(50);
	$data = []; // Массив для передачи в запрос мерджа

	foreach ($merge_data as $key => $entities) {
		$logger->log(count($entities) . ' entities to merge');
		$entities_data = $api->find('contacts', ['id' => $entities]);
		write_to_file($backup_file, $entities_data); // Пишем исходные
		$logger->log(count($entities_data) . ' entities found in account. Preparing merge data...');

		// Сортируем элементы по last_modified по убыванию (первый элемент - последний обновленный)
		// значения некоторых полей результирующего элемента будем брать из последего обновленного
		$modified_dates = array_column($entities_data, 'last_modified');
		array_multisort($modified_dates, SORT_DESC, $entities_data);
		$entities_ids = array_column($entities_data, 'id');

		// Если получено меньше сущностей, чем было в файле
		if (count($entities) !== count($entities_ids)) {
			$keys = array_diff($entities, $entities_ids);
			foreach ($keys as $key) {
				write_to_file($unmerged_file, $key);
			}
		}

		// Формирование массива для передачи в запрос мерджа
		$data = [
			'USER_LOGIN' => CUSTOMERS_API_USER_LOGIN,
			'USER_HASH' => CUSTOMERS_API_USER_HASH
		];

		$last_modified_contact = $entities_data[0]; // Последний обновленный контакт из текущей группы дублей)

		$result_name = $last_modified_contact['name'];
		$result_user = $last_modified_contact['responsible_user_id'];
		$result_company = (int)$last_modified_contact['linked_company_id'];
		$result_tags = array_column($last_modified_contact['tags'], 'id');

		$data['id'] = $entities_ids;
		$data['result_element'] = [
			'NAME'         => $result_name,
			'MAIN_USER_ID' => $result_user,
			'COMPANY_UID'  => $result_company,
			'TAGS'         => $result_tags
		];

		// Считаем количество уникальных сделок по текущим контактам (необходимо передать в запрос мерджа)
		$all_leads = array_column($entities_data, 'linked_leads_id');
		$leads_ids = [];
		foreach ($all_leads as $group) {
			if (count($group)) {
				foreach ($group as $lead_id) {
					$leads_ids[] = $lead_id;
				}
			}
		}
		$leads_count = count(array_unique($leads_ids));
		if ($leads_count) {
			$data['result_element']['LEADS'] = $leads_count;
		}

		$custom_fields_group = array_column($entities_data, 'custom_fields');

		$fields = search_fields_values([$custom_fields_group[0]], $standard_fields);  // Берем поля только первого контакта(последнего обновленного). Если поле пустое, после объединения тоже будет пустым
		if (count($fields)) {
			foreach ($fields as $field_id => $field_value) {
				$data['result_element']['cfv'][$field_id] = $field_value;
			}
		}

		$multi_fields = search_fields_values($custom_fields_group, $multi_values_fields, TRUE);  // Ищем по всем контактам, берем все уникальные значения
		if (count($multi_fields)) {
			foreach ($multi_fields as $field_id => $values_groups) {
				$field_enums = [];
				foreach ($contacts_custom_fields_info as $custom_field) {
					if ($custom_field['id'] == $field_id) {
						$field_enums = $custom_field['enums'];
						break;
					}
				}
				foreach ($values_groups as $values) {
					foreach ($values as $value) {
						// Строка вида "{"DESCRIPTION":"WORK","VALUE":"84994994949"}"
						$data['result_element']['cfv'][$field_id][] = '{"DESCRIPTION":"'.$field_enums[$value['enum']].'","VALUE":"'.$value['value'].'"}';
					}
				}
			}
		}
	}

	// Выполнение мерджа (компонент crm/elements.merge.save)
	$logger->log('starting merge...');

	$link = AMO_DEFAULT_PROTOCOL . '://' . AMO_CUSTOMERS_US_SUBDOMAIN . '.' . (AMO_DEV_MODE ? HOST_DIR_NAME . '.amocrm2.com' : 'amocrm.com');
	$link .= '/ajax/contacts/merge/save';

	$curl->init($link);
	$curl->option(CURLOPT_POSTFIELDS, http_build_query($data));
	$curl->exec();
	$info = $curl->info();
	$result = json_decode($curl->result(), TRUE);

	if (isset($result['response']) && $result['response'] === 'success') {
		$logger->log('SUCCESS. Result element id: ' . $result['base_id']);
	} else {
		$logger->error('ERROR. Results logged');
		$log = [$info, $data];
		write_to_file($errors_file, $log);
	}
}

/**
 * Поиск значений по custom_fields элементов
 * @param  array   $custom_fields_group - массив custom_fields элементов
 * @param  array   $fields_ids          - массив айдишников искомых полей
 * @param  bool    $all                 - TRUE - для полей с неск. значениями, FALSE - с одним
 * @return array   массив значений вида field_id => field_value | field_id => [[field_values], [field_values], ...]
 */
function search_fields_values($custom_fields_group, $fields_ids, $all = FALSE) {
	$result = [];
	foreach ($fields_ids as $field_id) {
		foreach ($custom_fields_group as $custom_fields) {
			foreach ($custom_fields as $field) {
				if ($field['id'] === $field_id) {
					if ($all) {
						$result[$field['id']][] = $field['values'];
					} else {
						$result[$field['id']] = $field['values'][0]['value'];
					}
					break;
				}
			}
		}
	}
	return $result;
}

function write_to_file($path, $data, $encode = TRUE) {
	if ($encode) {
		$data = json_encode($data);
	}
	file_put_contents($path, $data . "\n", FILE_APPEND);
}
