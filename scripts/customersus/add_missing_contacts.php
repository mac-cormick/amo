<?php
use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Логика скрипта:
 * получить группу дублей-контактов из сформированного файла
 * пройтись по полям, сформировав массив для передачи в /ajax/contacts/merge/save
 * значения полей, способных иметь несколько значений(Phone, Email, ...), объединяются в рез. элементе
 * значения полей, способных иметь только одно значение, берутся у последнего обновленного элемента из группы дублей
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
        ->add(new Optional('offset', 'o', 'limit_offset parameter', Param::TYPE_INT))
        ->add(new Optional('count', 'c', 'count of entities getting by one request', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($e->getMessage() . "\n" . $params->get_info());
    die;
}

$files_path = $params->get('dir');
$offset = (int)$params->get('offset');
$count = (int)$params->get('count');

$offset = ($offset > 0) ? $offset : 0;
$count = ($count > 0) ? $count : 250;

$account_field_empty = $files_path . '/amc_account_field_empty.txt';
$existing_contacts = $files_path . '/amc_existing_contacts.txt';
$new_contacts = $files_path . '/amc_new_contacts.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error in customers\n");
}

$leads_result = TRUE;
$account_field_id = 1235545;
$user_field_id = 1235547;

do {
    $logger->log('getting leads... OFFSET: ' . $offset);
//	$leads = $api->find('leads', ['limit_rows' => $count, 'limit_offset' => $offset]);
    // Получение сделок (метод find класса Api_Client возвращает удаленные сделки как актуальные)
    $link = AMO_DEFAULT_PROTOCOL . '://' . AMO_CUSTOMERS_US_SUBDOMAIN . '.' . (AMO_DEV_MODE ? HOST_DIR_NAME . '.amocrm2.com' : 'amocrm.com');
    $link .= '/api/v2/leads?USER_LOGIN=' . CUSTOMERS_API_USER_LOGIN . '&USER_HASH=' . CUSTOMERS_API_USER_HASH .  '&limit_rows=' . $count . '&limit_offset=' . $offset;
    $curl->init($link);
//	$curl->option(CURLOPT_POSTFIELDS, http_build_query($data));
    $curl->exec();
//	$info = $curl->info();
    $result = json_decode($curl->result(), TRUE);
//	var_dump($result);die();
    $leads = $result['_embedded']['items'];
//	var_dump(array_column($leads, 'id'));
    $offset += $count;
    if (!$leads) {
        $logger->log('0 leads received');
        $leads_result = FALSE;
        break;
    }
    // Формирование массивов соответствий lead_id => account_id и lead_id => [contacts_ids]
    $logger->log('Checking ' . count($leads). ' leads...');
    $by_account = [];
    $by_contacts = [];
    $accounts_ids = [];
    foreach ($leads as $lead) {
        $filled = FALSE;
        foreach ($lead['custom_fields'] as $field) {
            if ($field['id'] == $account_field_id) {
                $filled = TRUE;
                $account_id = (int)$field['values'][0]['value'];
                // Если есть дубль по полю account ID - удалим предыдущую, чтобы оставить последнюю обновленную
                if (array_search($account_id, $by_account)) {
                    unset ($by_account[$lead['id']]);
                }
                $by_account[$lead['id']] = $account_id;
                $accounts_ids[] = $account_id;
                break;
            }
        }
        if ($filled) {
            $lead_contacts_ids = $lead['contacts']['id'];
            $by_contacts[$lead['id']] = $lead_contacts_ids;
            foreach ($lead_contacts_ids as $id) {
                $contacts_ids[] = $id;
            }
        } else {
            write_to_file($account_field_empty, $lead['id']);
        }
    }
//	var_dump($by_contacts);
//	// Формирование массива элементов вида lead_id => [contacts_ids]
//	$contacts = $api->get_leads_links(array_column($leads, 'id'));
//	$by_contacts = [];
//	$contacts_ids = [];
//	if (count($contacts)) {
//		foreach ($contacts as $contact) {
//			$by_contacts[$contact['lead_id']][] = $contact['contact_id'];
//			$contacts_ids[] = $contact['contact_id'];
//		}
//	}
    // Формированеи массива соответсвий contact_id => user_id
    $by_user = [];
    $contacts_chunks = array_chunk($contacts_ids, $count);
//    var_dump($contacts_chunks);
    foreach ($contacts_chunks as $contacts_chunk) {
        $contacts = $api->find('contacts', ['id' => $contacts_chunk]);
//			var_dump($contacts);
        foreach ($contacts as $contact) {
//			$user_id = 0;
            foreach ($contact['custom_fields'] as $field) {
                if ($field['id'] == $user_field_id) {
                    $user_id = (int)$field['values'][0]['value'];
                    // Если есть дубль по полю user ID - удалим предыдущий, чтобы оставить последний обновленный
                    if (array_search($user_id, $by_user)) {
                        unset ($by_user[$contact['id']]);
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
        $accounts_ids = array_map('intval', $accounts_ids);
        $in = '(' . implode(',', $accounts_ids) . ')';
        $sql = "SELECT PROPERTY_54 as user_id, PROPERTY_55 as account_id FROM b_iblock_element_prop_s7 WHERE PROPERTY_55 IN " . $in;
        $logger->log($sql);
        $resource = $db->query($sql);
        while ($row = $resource->fetch(FALSE)) {
            $db_result[$row['account_id']][] = $row['user_id'];
        }
    }
    $logger->separator(50);
    // Сравнение результатов, полученных в аккаунте и в базе
    $acc_result = [];
//    var_dump($by_contacts);
//    var_dump($by_user);
    foreach ($by_contacts as $lead_id => $contacts_ids) {
        $users_ids = [];
        $account_id = $by_account[$lead_id];
        foreach ($contacts_ids as $contacts_id) {
            $users_ids[] = $by_user[$contacts_id];
        }
        $acc_result[$account_id] = $users_ids; // теперь $acc_result и $db_result - массивы с одинаковыми ключами(account_id)
    }
//    var_dump($acc_result);
    // получение массива вида account_id => [users_ids (отсутствующие в аккаунте, но имеющиеся в базе)]
    $diffs = [];
    foreach ($db_result as $account_id => $users_ids) {
        $diffs[$account_id] = array_diff($users_ids, $acc_result[$account_id]);
    }
//	var_dump($diffs);

    // Формирование файлов - для привязки существующих контактов(lead_id => [contacts_ids]) и для создания и привязки новых, отсутствующих в аккаунте(lead_id => [users_ids])
    foreach ($diffs as $account_id => $users_ids) {
        if (count($users_ids)) {
            $existing_contacts_data = [];
            $new_contacts_data = [];
            $lead_id = array_search($account_id, $by_account);
            foreach ($users_ids as $user_id) {
                $contact_id = array_search($user_id, $by_user);
                if ($contact_id) {
                    $existing_contacts_data[$lead_id][] = $contact_id;
                } else {
                    $new_contacts_data[$lead_id][] = $user_id;
                }
            }
            if (count($existing_contacts_data)) {
                write_to_file($existing_contacts, $existing_contacts_data);
            } elseif (count($new_contacts_data)) {
                write_to_file($new_contacts, $new_contacts_data);
            }
        }
    }
} while ($leads_result);

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}