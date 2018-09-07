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
        ->add(new Optional('offset', 'o', 'limit_offset parameter', Param::TYPE_INT))
        ->add(new Optional('count', 'c', 'count of entities getting by one request', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
        ->add(new Required('entity', 'e', 'entity type(leads or customers)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($e->getMessage() . "\n" . $params->get_info());
    die;
}

$files_path = $params->get('dir');
$entity_type = $params->get('entity');
$offset = (int)$params->get('offset');
$count = (int)$params->get('count');

$offset = ($offset > 0) ? $offset : 0;
$count = ($count > 0) ? $count : 250;

$account_field_empty = $files_path . '/amc_account_field_empty.txt';
$diffs_file = $files_path . '/amc_diffs_file.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error\n");
}

switch ($entity_type) {
    case 'leads':
        $account_field_id = 1235545;
        break;
    case 'customers':
        $account_field_id = 1235625;
        break;
    default:
        die("unsupported entity type parameter! leads or customers allowed\n");
}

$entities_result = TRUE;
$user_field_id = 1235547;

do {
    $logger->log('getting entities... OFFSET: ' . $offset);
    $entities = $api->find($entity_type, ['limit_rows' => $count, 'limit_offset' => $offset]);
//  var_dump($entities); die();
//  // Получение сделок (метод find класса Api_Client возвращает удаленные сделки как актуальные)
//  $link = AMO_DEFAULT_PROTOCOL . '://' . AMO_CUSTOMERS_US_SUBDOMAIN . '.' . (AMO_DEV_MODE ? HOST_DIR_NAME . '.amocrm2.com' : 'amocrm.com');
//  $link .= '/api/v2/leads?USER_LOGIN=' . CUSTOMERS_API_USER_LOGIN . '&USER_HASH=' . CUSTOMERS_API_USER_HASH .  '&limit_rows=' . $count . '&limit_offset=' . $offset;
//  $curl->init($link);
//  $curl->exec();
//  $result = json_decode($curl->result(), TRUE);
//  $leads = $result['_embedded']['items'];
    $offset += $count;

    if (!$entities) {
        $logger->log('0 entities received');
        $entities_result = FALSE;
        break;
    }

    // Формирование массивов соответствий lead_id => account_id и lead_id => [contacts_ids]
    $logger->log('Checking ' . count($entities). ' entities...');
    $by_account = [];
    $by_contacts = [];
    $accounts_ids = [];
    $contacts_ids = [];

    foreach ($entities as $entity) {
        $filled = FALSE;
        foreach ($entity['custom_fields'] as $field) {
            if ($field['id'] == $account_field_id) {
                $filled = TRUE;
                $account_id = (int)$field['values'][0]['value'];
                // Если есть дубль по полю account ID - удалим предыдущую, чтобы оставить последнюю обновленную
                if ($key = array_search($account_id, $by_account)) {
                    unset ($by_account[$key]);
                }
                $by_account[$entity['id']] = $account_id;
                $accounts_ids[] = $account_id;
                break;
            }
        }
        if ($filled) {
            $entity_contacts_ids = $entity['contacts']['id'];
            $by_contacts[$entity['id']] = $entity_contacts_ids;
            foreach ($entity_contacts_ids as $id) {
                $contacts_ids[] = $id;
            }
        } else {
            write_to_file($account_field_empty, $entity['id'], FALSE);
        }
    }

    // Формированеи массива соответсвий contact_id => user_id
    $by_user = [];
    $contacts_chunks = array_chunk($contacts_ids, $count);

    foreach ($contacts_chunks as $contacts_chunk) {
        $contacts = $api->find('contacts', ['id' => $contacts_chunk]);
        foreach ($contacts as $contact) {
            foreach ($contact['custom_fields'] as $field) {
                if ($field['id'] == $user_field_id) {
                    $user_id = (int)$field['values'][0]['value'];
                    // Если есть дубль по полю user ID - удалим предыдущий, чтобы оставить последний обновленный
                    if ($key = array_search($user_id, $by_user)) {
                        unset ($by_user[$key]);
                    }
                    $by_user[$contact['id']] = $user_id;
                    break;
                }
            }
        }
    }
    // формирование массива элементов вида account_id => [users_ids] по результату запроса в базу
    $db_result = [];

    if (count($accounts_ids)) {
        $logger->log(count($accounts_ids) . ' leads found with unique account ID field');
        $logger->log('Searching users by account id for these leads in database...');
        $accounts_ids = array_map('intval', $accounts_ids);
        $in = '(' . implode(',', $accounts_ids) . ')';
        $sql = "SELECT PROPERTY_54 as user_id, PROPERTY_55 as account_id FROM b_iblock_element_prop_s7 WHERE PROPERTY_55 IN " . $in;
        $resource = $db->query($sql);
        while ($row = $resource->fetch(FALSE)) {
            $db_result[$row['account_id']][] = $row['user_id'];
        }
    }

    // Сравнение результатов, полученных в аккаунте и в базе
    $acc_result = [];

    foreach ($by_contacts as $entity_id => $contacts_ids) {
        $users_ids = [];
        $account_id = $by_account[$entity_id];
        foreach ($contacts_ids as $contacts_id) {
            $users_ids[] = $by_user[$contacts_id];
        }
        $acc_result[$account_id] = $users_ids; // теперь $acc_result и $db_result - массивы с одинаковыми ключами(account_id)
    }

    // получение массива вида account_id => [users_ids (отсутствующие в аккаунте, но имеющиеся в базе)]
    $diffs = [];
    foreach ($db_result as $account_id => $users_ids) {
        $diffs[$account_id] = array_diff($users_ids, $acc_result[$account_id]);
    }

    // Формирование файла данных для апдейта сделок
    $i=0;
    foreach ($diffs as $account_id => $users_ids) {
        if (count($users_ids)) {
            $i++;
            $diff_data = [];
            $entity_id = array_search($account_id, $by_account);
            foreach ($users_ids as $user_id) {
                $diff_data[$entity_id][] = $user_id;
            }
            write_to_file($diffs_file, $diff_data);
        }
    }
    $logger->log($i . ' entities would be written to file for update');
    $logger->separator(50);
} while ($entities_result);

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
