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
        ->add(new Required('field', 'f', 'user ID contact card\'s custom field\'s ID', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp: /tmp/some_folder)', Param::TYPE_STRING))
        ->add(new Optional('count', 'c', 'Count of contacts updating for one request', Param::TYPE_INT))
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

$update_file = $files_path . '/uid-update-data.txt';
$errors_file = $files_path . '/uid-errors.json';
$errors_request_file = $files_path . '/uid-error-request.json';

$count = ($count > 0) ? $count : 50;
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
$line_get_result = TRUE;
while ($line_get_result) {
    $lines_count = $line_number + $i * $count;
    $contacts_update_items = [];
    $logger->log('update-file\'s current line number: ' . $lines_count);
    $i++;
    for ($x=0; $x<$count; $x++) {
        $contact_update_str = fgets($update_file_open);
        if (!$contact_update_str) {
            fclose($update_file_open);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }
        $contacts_update_items[] = json_decode($contact_update_str, TRUE);
    }
    $logger->log(count($contacts_update_items) . ' contacts got from file');

    // Формирование массива данных для апдейта
    $items = [];
    foreach ($contacts_update_items as $contacts_update_item) {
        if (is_array($contacts_update_item)) {
            foreach ($contacts_update_item as $contact_id => $user_id) {
                $items[$contact_id] = $user_id;
            }
        } else {
            $logger->log('incorrect format: ' . $contacts_update_item);
        }
    }
    $contacts = [];
    $contacts = $api->find('contacts', ['id' => array_keys($items)]);
    $update_data_items = [];
    foreach ($contacts as $contact) {
        $id = $contact['id'];
        $update_data_item = [
            'id' => $id,
            'last_modified' => API_Helpers::update_last_modified($contact['last_modified']),
            'custom_fields' => [['id' => $field_id, 'values' => [['value' => $items[$id]]]]],
        ];
        $update_data_items[] = $update_data_item;
    }
    $update_result = $api->update('contacts', $update_data_items);

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
}
