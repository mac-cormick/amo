<?php

use Cli\Helpers\Api_Client;
use Helpers\API\Account\API_Helpers;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];

$curl = $container['curl'];

$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error in customers\n");
}

$params = new \Cli\Params\CLI_Params();

try {
	$params
		->add(new Required('field', 'f', 'Account id custom field\'s ID', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp: /tmp/amol)', Param::TYPE_STRING))
		->add(new Optional('count', 'c', 'Count of leads updating for one request', Param::TYPE_INT))
		->add(new Optional('position', 'p', 'File descriptor position', Param::TYPE_INT))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($params->get_info());
	die;
}

$files_path = $params->get('dir');
$field_id = (int)$params->get('field');
$count = (int)$params->get('count');
$position = (int)$params->get('position');

$update_file = $files_path . '/update-data.txt';
$errors_file = $files_path . '/errors.json';
$errors_request_file = $files_path . '/error-request.json';

$count = ($count > 0) ? $count : 30;
$position = ($position > 0) ? $position : 0;

$update_file_open = fopen($update_file, 'rt');
if (isset($position)) {
	fseek($update_file_open, $position);
}

if (!$update_file_open) {
	$logger->error('Opening file error');
	die();
}

$lead_update_str = '';
while ($lead_update_str !== FALSE) {
	// Получение данных для апдейта из файла
	$leads_update_items = [];

	$file_position = ftell($update_file_open);
	$logger->log('descriptor current position: ' . $file_position);
	for ($x=0; $x<$count; $x++) {
		$lead_update_str = fgets($update_file_open);
		if (!$lead_update_str) {
			fclose($update_file_open);
			$logger->log('End of file');
			break;
		}
		$leads_update_items[] = json_decode($lead_update_str, TRUE);
	}
	$logger->log(count($leads_update_items) . ' leads got from file');

	// Формирование массива данных для апдейта
	$items = [];
	foreach ($leads_update_items as $leads_update_item) {
		if (is_array($leads_update_item)) {
			foreach ($leads_update_item as $lead_id => $comp_id) {
				$items[$lead_id] = $comp_id;
			}
		} else {
			$logger->log('incorrect format: ' . $leads_update_item);
		}
	}
	$leads = [];
	$leads = $api->find('leads', ['id' => array_keys($items)]);
	$update_data_items = [];
	foreach ($leads as $lead) {
		$lead_id = $lead['id'];
		$update_data_item = [
			'id' => $lead_id,
			'last_modified' => API_Helpers::update_last_modified($lead['last_modified']),
			'custom_fields' => [['id' => $field_id, 'values' => [['value' => $items[$lead_id]]]]],
		];
		$update_data_items[] = $update_data_item;
	}

	$update_result = $api->update('leads', $update_data_items);

	$resp_code = $api->get_response_code();
	$response = $api->get_response();
	$last_request = $api->get_last_request();

	if ($resp_code !== 200 && $resp_code !== 100) {
		write_to_file($errors_file, $response);
		write_to_file($errors_request_file, $last_request);
		$logger->error('request error. Code ' . $error_code);
	} elseif (count($response['response']['leads']['update']['errors']) > 0) {
		$logger->error('update errors found');
		write_to_file($errors_file, $response['response']['leads']['update']['errors']);
		write_to_file($errors_request_file, $last_request);
	} else {
		$logger->log('updated successfully! Response code: ' . $resp_code);
	}
}

function write_to_file($path, $data, $encode = TRUE) {
	if ($encode) {
		$data = json_encode($data);
	}
	file_put_contents($path, $data . "\n", FILE_APPEND);
