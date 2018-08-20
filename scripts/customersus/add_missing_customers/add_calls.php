<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Scripts\Single\Customersus\Helpers\Account_Helpers;

/**
 * Логика скрипта:
 * Добавить в сделки, полуенные из файла, примечание типа CALL IN
 * в аккаунте добавятся покупатели по событию входящего звонка(при настройке в воронке)
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];

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

$update_file = $files_path . '/amc_update_file.txt';

$files[] = $calls_add_errors_file = 'amc_calls_add_errors.txt';
$files[] = $added_notes_file = 'amc_added_notes.txt';

if ($line === 0) {
    foreach ($files as $file) {
        $file_path = $files_path . '/' . $file;
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
}

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error\n");
}

$helper = new Account_Helpers($logger, $api, NULL, $files_path);

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

    $leads = $helper->get_file_content($file_handle, $update_count);
    if (!$leads) {
        $data_get_result = FALSE;
        break;
    }

    $leads_ids = [];
    foreach ($leads as $lead) {
        $leads_ids[] = key($lead);
    }

    $logger->log('Getting ' . count($leads_ids) . ' leads...');
    $leads_for_update = $api->find('leads', ['id' => $leads_ids]);

    if (count($leads_for_update)) {
        // Добавляем примечания типа CALL IN
        $logger->log('Adding calls to ' . count($leads_for_update) . ' leads ...');
        $calls_add_data = [];
        foreach ($leads_for_update as $lead) {
            $calls_add_data[] = [
                'element_id'   => $lead['id'],
                'element_type' => AMO_LEADS_TYPE,
                'note_type'    => AMO_NOTE_TYPE_CALL_IN,
                'text'         => 'test',
            ];
        }

        $result = $api->action('add', 'notes', $calls_add_data);
        $response = $api->get_response();

        // Пишем в файл ID добавленных примечаний для удаления
        if (!empty($response['response']['notes']['add'])) {
            $added_notes = $response['response']['notes']['add'];
            $notes_ids = array_column($added_notes, 'id');
            $helper->write_to_file($added_notes_file, $notes_ids); // на случай если что-то не удалится
        } else {
            $helper->write_to_file($calls_add_errors_file, ['data' => $calls_add_data, 'response' => $response]);
        }
        $logger->separator(50);
    }
}
