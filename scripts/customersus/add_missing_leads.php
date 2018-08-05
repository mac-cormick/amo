<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

$app_path = realpath(dirname(__FILE__) . '/../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
    $params
		->add(new Optional('line', 'l', 'reading file current line number', Param::TYPE_INT))
        ->add(new Optional('count', 'c', 'count of entities updating by one request', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($e->getMessage() . "\n" . $params->get_info());
    die;
}

$files_path = $params->get('dir');
$line = (int)$params->get('line');
$count = (int)$params->get('count');

$line_number = ($line > 0) ? $line : 0;
$count = ($count > 0) ? $count : 250;
$offset = 0;

$leads_add_data = $files_path . '/aml_leads_add_data.txt';
$files[] = $errors_file = $files_path . '/aml_errors_file.txt';
$files[] = $request_errors_data = $files_path . '/aml_request_errors_data.txt';

if ($line_number === 0) {
    foreach ($files as $file) {
        unlink($file);
    }
}

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error\n");
}


// получение данных для добавления сделок из файла
$file_handle = fopen($leads_add_data, 'rt');
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
$line_get_result = TRUE;

while ($line_get_result) {
    $lines_count = $line_number + $i * $count;
    $logger->log('diffs file\'s current line number: ' . $lines_count);
    $i++;
    $leads_add_items = [];
    for ($x = 0; $x < $count; $x++) {
        $next_str = fgets($file_handle);
        if (!$next_str) {
            fclose($file_handle);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }
        $leads_add_items[] = json_decode($next_str, TRUE);
    }

    $logger->log('Adding ' . count($leads_add_items) . ' leads...');
    $result = $api->add('leads', $leads_add_items);
    $resp_code = $api->get_response_code();
    $response_info = $api->get_response_info();

    if ($resp_code !== 200 && $resp_code !== 100) {
        write_to_file($errors_file, $response_info);
        write_to_file($request_errors_data, $leads_add_items);
        $logger->error('request error. Code ' . $resp_code);
    } elseif (count($result['_embedded']['errors'])) {
        $logger->error('request errors found and logged');
        write_to_file($errors_file, $result['_embedded']['errors']);
        write_to_file($request_errors_data, $leads_add_items);
    } else {
        $logger->log('Added successfully!');
    }
}

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}