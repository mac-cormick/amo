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
		->add(new Optional('count', 'c', 'entities updating by one request count', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
		->add(new Required('file', 'f', 'update file choice (new OR lost)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$file_choice = $params->get('file'); // выбранный файла для апдейта
$count = (int)$params->get('count');

$update_count = ($count > 0) ? $count : 250;
$new_lead_status_id = 993229;

$new_leads_file = 'cws_new_leads.txt';
$lost_leads_file = 'cws_lost_leads.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error\n");
}

$helper = new Account_Helpers($logger, $api, $db, $files_path);

switch ($file_choice) {
	case 'new':
		$file_name = $new_leads_file;
		$status_id = $new_lead_status_id;
		break;
	case 'lost':
		$file_name = $lost_leads_file;
		$status_id = AMO_LEAD_STATUS_LOST;
		break;
	default:
		die("Unsupported file choice! new OR lost are allowed\n");
}

$update_file = $files_path . '/' . $file_name;

$file_handle = fopen($update_file, 'rt');
if (!$file_handle) {
	$logger->error('Opening file error');
	die();
}

$i = 0;
$line_number = 0;
$data_get_result = TRUE;

while ($data_get_result) {
	$lines_count = $line_number + $i * $update_count;
	$logger->log('diffs file\'s current line number: ' . $lines_count);
	$i++;

	$leads_ids = $helper->get_file_content($file_handle, $update_count, FALSE);
	if (!$leads_ids) {
		$data_get_result = FALSE;
		break;
	}

	$helper->move_to_status($leads_ids, $status_id, CUSTOMERSUS_PIPELINE_ID_FIRST);
	$logger->separator(50);
}
