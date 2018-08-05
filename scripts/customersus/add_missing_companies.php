<?php

use Cli\Helpers\Api_Client;
use Helpers\API\Account\API_Helpers;
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

$companies_add_data = $files_path . '/aml_companies_add_data.txt';
$exist_companies_data = $files_path . '/aml_companies_exist.txt';
$files[] = $errors_file = $files_path . '/amc_errors_file.txt';
$files[] = $request_errors_data = $files_path . '/amc_request_errors_data.txt';

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

$leads_result = TRUE;
$account_field_id = 1235545;

$leads_by_account = [];

// Формирование массива соответствий lead_id => account_id по полю account ID в сделках
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

    foreach ($leads as $lead) {
        $filled = FALSE;
        foreach ($lead['custom_fields'] as $field) {
            if ($field['id'] == $account_field_id) {
                $filled = TRUE;
                $account_id = (int)$field['values'][0]['value'];
                // Если есть дубль по полю account ID - удалим предыдущую, чтобы оставить последнюю обновленную
                if ($key = array_search($account_id, $leads_by_account)) {
                    unset ($leads_by_account[$key]);
                }
                $leads_by_account[$lead['id']] = $account_id;
                $accounts_ids[] = $account_id;
                break;
            }
        }
        if (!$filled) {
            write_to_file($account_field_empty, $lead['id'], FALSE);
        }
    }
    $logger->separator(50);
} while ($leads_result);
$logger->log(count($leads_by_account) . ' leads found with unique account ID field');
$logger->separator(100);

// получение данных для добавления компаний из файла
$file_handle = fopen($companies_add_data, 'rt');
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
    $logger->log('add companies file\'s current line number: ' . $lines_count);
    $i++;
    $companies_add_items = [];
    for ($x = 0; $x < $count; $x++) {
        $next_str = fgets($file_handle);
        if (!$next_str) {
            fclose($file_handle);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }
        $company_item = json_decode($next_str, TRUE);
        $company_lead = array_search($company_item['account_id'], $leads_by_account);
        $companies_add_items[] = [
            'name'     => $company_item['name'],
            'linked_leads_id' => $company_lead
        ];
    }

    $logger->log('Adding ' . count($companies_add_items) . ' companies...');
    $result = $api->add('company', $companies_add_items);
    $resp_code = $api->get_response_code();
    $response_info = $api->get_response_info();

    if ($resp_code !== 200 && $resp_code !== 100) {
        write_to_file($errors_file, $response_info);
        write_to_file($request_errors_data, $companies_add_items);
        $logger->error('request error. Code ' . $resp_code);
    } elseif (count($result['_embedded']['errors'])) {
        $logger->error('request errors found and logged');
        write_to_file($errors_file, $result['_embedded']['errors']);
        write_to_file($request_errors_data, $companies_add_items);
    } else {
        $logger->log('Added successfully!');
    }
}

$logger->separator(100);
$logger->log('updating existing companies...');

// получение данных для апдейта существующих компаний из файла
$file_handle = fopen($exist_companies_data, 'rt');
if (!$file_handle) {
    $logger->error('Opening file error');
    die();
}

$line_number = 0;
$i = 0;
$line_get_result = TRUE;

while ($line_get_result) {
    $lines_count = $line_number + $i * $count;
    $logger->log('update companies file\'s current line number: ' . $lines_count);
    $i++;
    $companies_update_items = [];
    $companies_ids = [];

    for ($x = 0; $x < $count; $x++) {
        $next_str = fgets($file_handle);
        if (!$next_str) {
            fclose($file_handle);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }
        $company_item = json_decode($next_str, TRUE);
        $companies_update_items[$company_item['company_id']] = $company_item['account_id'];
        $companies_ids[] = $company_item['company_id'];
    }

    $companies = $api->find('companies', ['id' => $companies_ids]);
    $companies_update_data = [];
    foreach ($companies as $company) {
        $account_id = $companies_update_items[$company['id']];
        $company_lead_id = array_search($account_id, $leads_by_account);
        $companies_update_data[] = [
            'id'     => $company['id'],
            'last_modified' => API_Helpers::update_last_modified($company['last_modified']),
            'linked_leads_id' => $company_lead_id
        ];
    }

    $logger->log('Updating ' . count($companies_update_data) . ' companies...');
    $result = $api->update('company', $companies_update_data);
    $resp_code = $api->get_response_code();
    $response_info = $api->get_response_info();

    if ($resp_code !== 200 && $resp_code !== 100) {
        write_to_file($errors_file, $response_info);
        write_to_file($request_errors_data, $companies_update_data);
        $logger->error('request error. Code ' . $resp_code);
    } elseif (count($result['_embedded']['errors'])) {
        $logger->error('request errors found and logged');
        write_to_file($errors_file, $result['_embedded']['errors']);
        write_to_file($request_errors_data, $companies_update_data);
    } else {
        $logger->log('Updated successfully!');
    }
}

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}