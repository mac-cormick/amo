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
        ->add(new Optional('line', 'l', 'File line number', Param::TYPE_INT))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($params->get_info());
    die;
}

$files_path = $params->get('dir');
$field_id = (int)$params->get('field');
$count = (int)$params->get('count');
$line = (int)$params->get('line');

$update_file = $files_path . '/update-data.txt';
$errors_file = $files_path . '/errors.json';
$errors_request_file = $files_path . '/error-request.json';

$count = ($count > 0) ? $count : 250;
$line = ($line > 0) ? $line : 0;
$line_number = $line;

$update_file_open = fopen($update_file, 'rt');

if (!$update_file_open) {
    $logger->error('Opening file error');
    die();
}

if ($line > 0) {
    while (!feof($update_file_open) && $line--) {
        fgets($update_file_open);
    }
}

// Получение данных для апдейта из файла
$i = 0;
$lead_update_str = '';
while (!feof($update_file_open)) {
    $line_number += $i * $count;
    $leads_update_items = [];
    $logger->log('update-file\'s current line number: ' . $line_number);
    $i++;

    for ($x=0; $x<$count; $x++) {
        $lead_update_str = fgets($update_file_open);
        if (!$lead_update_str) {
            fclose($update_file_open);
            $logger->log('End of file');
            break;
        }
        $leads_update_items[] = json_decode($lead_update_str, true);
    }
    $logger->log(count($leads_update_items) . ' leads got from file');
    var_dump($leads_update_items);die();

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

    if ($resp_code !== 200) {
        $logger->error('request error. Code ' . $resp_code);
        write_to_file($errors_file, $response);
        write_to_file($errors_request_file, $last_request);
    } elseif (count($response['response']['leads']['update']['errors']) > 0) {
        $logger->error('update errors found');
        write_to_file($errors_file, $response['response']['leads']['update']['errors']);
        write_to_file($errors_request_file, $last_request);
    } else {
        $logger->log('updated successfully!');
    }
}

function write_to_file($path, $data, $encode=true) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
