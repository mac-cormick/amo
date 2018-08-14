<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Scripts\Single\Customersus\Helpers\Account_Helpers;

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
$count = (int)$params->get('count');
$offset = (int)$params->get('offset');

$count = 250;
$offset = ($offset > 0) ? $offset : 0;

$files[] = $account_field_empty = 'mpl_account_field_empty.txt';
$files[] = $update_file = 'mpl_update_file.txt';

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

$account = new Account_Helpers($logger, $api, $db, $files_path);
$leads_result = TRUE;

do {
	$logger->log('getting leads... OFFSET: ' . $offset);
	$leads = $api->find('leads', ['limit_rows' => $count, 'limit_offset' => $offset]);
	$offset += $count;

	if (!$leads) {
		$logger->log('0 leads received');
		$leads_result = FALSE;
		break;
	}

	$logger->log('Checking ' . count($leads). ' leads...');
	foreach ($leads as $key => $lead) {
		if ($lead['status_id'] == AMO_LEAD_STATUS_WIN) {
			unset($leads[$key]);
		}
	}

	// Формирование массива соответствий lead_id => account_id по полю account ID в сделках
	$result = $account->field_associations($leads, AMO_CUSTOMERSUS_LEADS_CF_ACCOUNT_ID);

	if (!empty($result['result'])) {
		$leads_by_account = $result['result'];
		$accounts_ids = array_unique(array_values($leads_by_account));
	}

	$empty_field_leads = $result['empty_field_entities'];
	if (!empty($empty_field_leads)) {
		$account->write_to_file($account_field_empty, $empty_field_leads);
	}

	$logger->separator(50);
	$logger->log(count($leads_by_account) . ' leads found with filled account ID field & not-win status');

	if (isset($accounts_ids) && count($accounts_ids)) {
		$db_result = $account->get_accounts_info($accounts_ids, ['IBLOCK_ELEMENT_ID', 'PROPERTY_79']);
		if ($db_result) {
			$db_accounts_ids = [];
			foreach ($db_result as $item) {
				if (!empty($item['PROPERTY_79'])) {
					$db_accounts_ids[] = $item['IBLOCK_ELEMENT_ID'];
				}
			}
			$logger->log(count($db_accounts_ids) . ' accounts have payed orders');
		}
	}

	if (count($db_accounts_ids)) {
		foreach ($db_accounts_ids as $id) {
			$leads_for_update = array_keys($leads_by_account, $id);
			foreach ($leads_for_update as $lead_id) {
				$account->write_to_file($update_file, $lead_id, FALSE);
			}
		}
		$logger->log('leads for update wrote to file');
	}
	$logger->separator(100);
} while ($leads_result);
