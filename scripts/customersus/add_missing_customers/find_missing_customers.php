<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Scripts\Single\Customersus\Helpers\Account_Helpers;

/**
 * Проходит по сделкам в этапе "Успешно реализовано" и проверяет
 * по полю account ID наличие оплаченного периода в аккаунтах
 * формирует файл id сделок без оплаты но с текущим триалом(для переноса в статус "New Leads") и
 * файл id сделок без оплаты с оконченным триалом(для переноса в статус "Закрыто и не реализовано")
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];

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
$offset = (int)$params->get('offset');

$count = 250;
$offset = ($offset > 0) ? $offset : 0;

$files[] = $update_file = 'amc_update_file.txt';

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

$helper = new Account_Helpers($logger, $api, NULL, $files_path);

$customers_by_account = [];
$logger->log('Checking existing customers...');
do {
    $customers = $api->find('customers', ['limit_rows' => $count, 'limit_offset' => $offset]);
    $offset += $count;
//    var_dump($customers);die();
    if ($customers) {
        $customers_by_account += $helper->check_names($customers);
    }
} while ($customers);
$logger->log(count($customers_by_account) . ' customers found with numbers in names');
$logger->separator(100);

$offset = 0;
do {
    $leads = $api->find('leads', ['status' => AMO_LEAD_STATUS_WIN, 'limit_rows' => $count, 'limit_offset' => $offset]);
    if ($leads) {
        $logger->log(count($leads) . ' leads got from account. Offset - ' . $offset);
    } else {
        $logger->log('0 leads got from account');
        break;
    }
    $offset += $count;

    $result = $helper->make_field_associations($leads, AMO_CUSTOMERSUS_LEADS_CF_ACCOUNT_ID);
    $leads_by_account = $result['result'];

    if (!empty($leads_by_account)) {
        $logger->log(count($leads_by_account) . ' leads have filled account ID field. Looking for customers...');
//        $accounts_ids = array_unique(array_values($leads_by_account));
    } else {
        $logger->log('0 leads have filled account ID field');
        continue;
    }

    $leads_without_customers = array_diff($leads_by_account, $customers_by_account); // ID сделок без покупателей
    if (!empty($leads_without_customers)) {
        $logger->log(count($leads_without_customers) . ' Leads haven\'t customers in account. Writing data to file...');
        foreach ($leads_without_customers as $lead_id => $account_id) {
//            $add_call_data = [
//                'element_id'   => $lead_id,
//                'element_type' => 2,
//                'note_type'    => 10
//            ];
            $helper->write_to_file($update_file, [$lead_id => $account_id]);
        }
    } else {
        $logger->log('All leads have customers in account');
    }
    $logger->separator(50);
} while ($leads);
