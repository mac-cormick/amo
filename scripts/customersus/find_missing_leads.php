<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Логика скрипта:
 * формирование массива соответствий вида ID сделки => ID аккаунта(по полю account ID в сделке) для всех сделок
 * формирование массива соответствий вида ID компании => ID аккаунта(по имени компании)
 * получение из базы список com аккаунтов
 * поиск по ID аккаунтов отсутствующих в аккаунте сделкок и формирование файлов для добавления сделок и компаний
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
    $params
        ->add(new Optional('count', 'c', 'count of entities getting by one request', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($e->getMessage() . "\n" . $params->get_info());
    die;
}

$files_path = $params->get('dir');
$count = (int)$params->get('count');

$count = ($count > 0) ? $count : 250;
$offset = 0;

$files[] = $account_field_empty = $files_path . '/aml_account_field_empty.txt';
$files[] = $exist_companies = $files_path . '/aml_companies_exist.txt';
$files[] = $companies_add_data = $files_path . '/aml_companies_add_data.txt';
$files[] = $leads_add_data = $files_path . '/aml_leads_add_data.txt';
//var_dump($files);

foreach ($files as $file) {
    unlink($file);
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

// формирование массива соответствий company_id => account_id (по имени компании)
$companies_result = TRUE;
$companies_by_account = [];
$offset = 0;

do {
    $logger->log('getting companies... OFFSET: ' . $offset);
    $companies = $api->find('companies', ['limit_rows' => $count, 'limit_offset' => $offset]);
    $offset += $count;

    if (!$companies) {
        $logger->log('0 companies received');
        $companies_result = FALSE;
        break;
    }

    $logger->log('Checking ' . count($companies). ' companies...');

    foreach ($companies as $company) {
        $company_name = $company['name'];
        $company_id = (int)$company['id'];
        $number_from_name = preg_replace("/[^0-9]/", '', $company_name);  // получение числовой части из названия
        if ($number_from_name) {
            if ($key = array_search($number_from_name, $companies_by_account)) {
                unset ($companies_by_account[$key]); // заменим на последнюю обновленную
            }
            $companies_by_account[$company_id] = $number_from_name;
        }
    }

    $logger->separator(50);
} while ($companies_result);

$logger->log(count($companies_by_account) . ' companies found with numbers in names');
$logger->separator(100);

// получение из базы аккаунтов, зарегистрированных на com
$rows = 1000;
$offset = 0;

$pipeline_id = AMO_DEV_MODE ? 852 : 29777;
$success_status_id = 142;
$closed_status_id = 143;
$new_lead_status_id = 993229;
$responsible_user_id = AMO_DEV_MODE ? NULL : 54443;

do {
    $sql = "
        SELECT
            id as account_id,
            subdomain,
            trial_end,
            trial_start,
            pay_start,
            pay_end
        FROM amo_accounts_view
        WHERE shard_type=2
        LIMIT $rows OFFSET $offset";

    $resource = $db->query($sql);
    $db_accounts_ids = [];
    $db_account_items = [];

    while ($row = $resource->fetch(FALSE)) {
        $db_accounts_ids[]   = $row['account_id'];
        $db_account_items[$row['account_id']] = [
            'subdomain'         => $row['subdomain'],
            'trial_begin'       => $row['trial_start'],
            'trial_end'         => $row['trial_end'],
            'paid_access_begin' => $row['pay_start'],
            'paid_access_end'   => $row['pay_end']
        ];
    }

    $logger->log(count($db_accounts_ids) . ' com accounts got from db with offset: ' . $offset);

    // формирование файлов для создания сделок и компаний, отсутствующих в аккаунте
    $missing_leads = array_diff($db_accounts_ids, $leads_by_account);
    $logger->log(count($missing_leads) . ' accounts has not leads. Preparing data for creating leads and companies...');

    foreach ($missing_leads as $account_id) {
        $account_item = $db_account_items[$account_id];
        $trial_begin       = $account_item['trial_begin'];
        $trial_end         = $account_item['trial_end'];
        $paid_access_begin = $account_item['paid_access_begin'];
        $paid_access_end   = $account_item['paid_access_end'];

        if (!empty($paid_access_end)) {
            $status = $success_status_id;
        } elseif (empty($paid_access_end) && ($trial_end > time())) {
            $status = $new_lead_status_id;
        } else {
            $status = $closed_status_id;
        }

        $lead_add_data = [
            'name'                => 'New Account: ' . $account_id,
            'date_create'         => $trial_begin,
            'responsible_user_id' => $responsible_user_id,
            'status_id'           => $status,
            'pipeline_id'         => $pipeline_id,
            'custom_fields'       => [
                ['id' => $account_field_id, 'values' => [['value' => $account_id]]]
            ]
        ];

        if ($company_id = array_search($account_id, $companies_by_account)) {
            $exist_company = [
                'company_id' => $company_id,
                'account_id' => $account_id
            ];
            write_to_file($exist_companies, $exist_company);
        } else {
            $subdomain = $db_account_items[$account_id]['subdomain'];
            $company_add_data = [
                'name' => $account_id . '|' . $subdomain,
                'account_id' => $account_id
            ];
            write_to_file($companies_add_data, $company_add_data);
        }
        write_to_file($leads_add_data, $lead_add_data);
    }

    $logger->log('data written to files');

    $logger->separator(50);
    $offset += $rows;
} while (count($db_account_items));


function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
