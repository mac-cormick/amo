<?php

use Cli\Helpers\Api_Client;
use Helpers\API\Account\API_Helpers;
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
		->add(new Optional('count', 'c', 'count of entities updating by one request', Param::TYPE_INT))
		->add(new Optional('line', 'l', 'updates file\'s current cycle line number', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$count = (int)$params->get('count');
$line = (int)$params->get('line');

$update_count = ($count > 0) ? $count : 250;
$line_number = ($line > 0) ? $line : 0;

$update_file = $files_path . '/mpl_update_file.txt';
$files[] = REQUEST_ERRORS_FILE_NAME;
$files[] = REQUEST_ERRORS_DATA_FILE_NAME;

if ($line_number === 0) {
	foreach ($files as $file) {
		unlink($files_path . '/' . $file);
	}
}

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error\n");
}

$account = new Account_Helpers($logger, $api, NULL, $files_path);

$file_handle = fopen($update_file, 'rt');
if (!$file_handle) {
	$logger->error('Opening file error');
	die();
}
if ($line > 0) {
	while (!feof($file_handle) && $line--) {
		fgets($file_handle);
	}
}

$i = 0;
$data_get_result = TRUE;

while ($data_get_result) {
	$lines_count = $line_number + $i * $update_count;
	$logger->log('diffs file\'s current line number: ' . $lines_count);
	$i++;

	$leads_ids = $account->get_file_content($file_handle, $update_count, FALSE);
	if (!$leads_ids) {
		$data_get_result = FALSE;
		break;
	}

	$logger->log('Getting ' . count($leads_ids) . ' leads...');
	$leads_for_update = $api->find('leads', ['id' => $leads_ids]);

	if (count($leads_for_update)) {
		$logger->log('Updating ' . count($leads_for_update) . ' leads...');
		$update_data = [];
		foreach ($leads_for_update as $lead) {
			$update_data[] = [
				'id' => $lead['id'],
				'last_modified' => API_Helpers::update_last_modified($lead['last_modified']),
				'status_id' => AMO_LEAD_STATUS_WIN,
				'pipeline_id' => CUSTOMERSUS_PIPELINE_ID_FIRST
			];
		}

		$account->process_post_request('leads', 'update', $update_data);
		$logger->separator(50);
	}
}
