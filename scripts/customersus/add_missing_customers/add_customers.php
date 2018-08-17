<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Scripts\Single\Customersus\Helpers\Account_Helpers;

/**
 * Проходит по сделкам в этапе "Успешно реализовано" и проверяет
 * по полю account ID наличие оплаченного периода в аккаунтах
 * формирует файл id сделок без оплаты но с текущим триалом(для переноса в статус "New Leads") и
 * файл id сделок без оплаты с оконченным триалом(для переноса в статус "Закрыто и не реализовано")
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
	$params
		->add(new Optional('offset', 'o', 'limit_offset parameter', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$offset = (int)$params->get('offset');

$count = 250;
$offset = ($offset > 0) ? $offset : 0;

$files[] = $new_leads_file = 'cws_new_leads.txt';
$files[] = $lost_leads_file = 'cws_lost_leads.txt';

if ($offset === 0) {
	foreach ($files as $file) {
		unlink($files_path . '/' . $file);
	}
}

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error\n");
}

$helper = new Account_Helpers($logger, $api, $db, $files_path);

do {
	$leads = $api->find('leads', ['status' => AMO_LEAD_STATUS_WIN, 'limit_rows' => $count, 'limit_offset' => $offset]);
	if ($leads) {
		$logger->log(count($leads) . ' leads got from account. Offset - ' . $offset);
	} else {
		$logger->log('0 leads got from account');
		break;
	}
	$offset += $count;

	$result = $helper->make_field_associations($leads, AMO_CUSTOMERSUS_LEADS_CF_ACCOUNT_ID);
	$leads_by_account = $result['result'];

	if (!empty($leads_by_account)) {
		$logger->log(count($leads_by_account) . ' leads have filled account ID field. Checking accounts for payment...');
		$accounts_ids = array_unique(array_values($leads_by_account));
	} else {
		$logger->log('0 leads have filled account ID field');
		continue;
	}

	$db_result = $helper->get_accounts_info($accounts_ids, ['IBLOCK_ELEMENT_ID', 'PROPERTY_68', 'PROPERTY_79']);
	if ($db_result) {
		$leads_for_update_count = 0;
		foreach ($db_result as $item) {
			// Если никогда не было оплаты
			if (!$item['PROPERTY_79']) {
				$leads_for_update_count++;
				$lead_id = array_search($item['IBLOCK_ELEMENT_ID'], $leads_by_account);
				// Если триал не окончен - будем перемещать в статус New Leads, else - в Lost
				if (strtotime($item['PROPERTY_68']) > time()) {
					$helper->write_to_file($new_leads_file, $lead_id, FALSE);
				} else {
					$helper->write_to_file($lost_leads_file, $lead_id, FALSE);
				}
			}
		}
	}
	$logger->log($leads_for_update_count ? $leads_for_update_count . ' leads without payed orders found. Ids wrote to files' : 'no leads without payed orders');
	$logger->separator(50);
} while ($leads);
