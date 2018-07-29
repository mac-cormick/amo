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
        ->add(new Optional('line', 'l', 'update file\'s current line number', Param::TYPE_INT))
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

$diffs_file = $files_path . '/amc_diffs_file.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error\n");
}

// Формирование массива соответствий contact_id => user_id для всех контактов аккаунта
$i = 0;
$contacts_count = 250;
$user_field_id = 1235547;
$phone_field_id = AMO_DEV_MODE ? 1277143 : 66196;
$email_field_id = AMO_DEV_MODE ? 1277144 : 66200;
$contacts_by_user = [];
$contacts_result = TRUE;

do {
    $contacts_result = $api->find('contacts', ['limit_rows' => $contacts_count, 'limit_offset' => $i * $contacts_count]);
//    var_dump($contacts_result);
//    var_dump(array_column($contacts_result, 'id'));

    $logger->log('Checking ' . count($contacts_result) . ' contacts by user ID field');
    foreach ($contacts_result as $contact) {
        foreach ($contact['custom_fields'] as $field) {
            if ($field['id'] == $user_field_id) {
                $user_id = $field['values'][0]['value'];
                // Если есть дубль по полю user ID - удалим предыдущий, чтобы оставить последний обновленный
                if ($key = array_search($user_id, $contacts_by_user)) {
                    unset ($contacts_by_user[$key]);
                }
                $contacts_by_user[$contact['id']] = $user_id;
                break;
            }
        }
    }

    $i++;

} while ($contacts_result);
//var_dump($contacts_by_user);
$logger->log(count($contacts_by_user) . ' found with unique user ID field');

// получение данных по сделкам для апдейта из файла
$diffs_file_open = fopen($diffs_file, 'rt');
if (!$diffs_file_open) {
    $logger->error('Opening file error');
    die();
}
if ($line > 0) {
    while (!feof($diffs_file_open) && $line--) {
        fgets($diffs_file_open);
    }
}

// Получение данных для апдейта из файла
$i = 0;
$line_get_result = TRUE;
$doubles_by_user_id = [];

while ($line_get_result) {
    $lines_count = $line_number + $i * $count;
    $logger->log('diffs file\'s current line number: ' . $lines_count);
    $i++;
    $exist_contacts = [];
    $no_contacts = [];
    $leads_ids = [];
    $logger->log('Checking ' . $count . ' leads...');
    for ($x=0; $x<$count; $x++) {
        $update_str = fgets($diffs_file_open);
        if (!$update_str) {
            fclose($diffs_file_open);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }

        $update_item = json_decode($update_str, TRUE);
        foreach ($update_item as $lead_id => $users_ids) {
            $leads_ids[] = $lead_id;
            foreach ($users_ids as $user_id) {
                if ($contact_id = array_search($user_id, $contacts_by_user)) {
                    $exist_contacts[$lead_id][] = $contact_id; // контакт найден в аккаунте
                } else {
                    $no_contacts[$lead_id][] = $user_id; // нет контакта в аккаунте, необходимо создать
                }
            }
        }
    }
    $logger->log(count($exist_contacts) . ' leads will be updated by adding existing contacts');
    $logger->log(count($no_contacts) . ' leads will be updated by adding new contacts');
//    var_dump($exist_contacts);
//    var_dump($no_contacts);

    $modified_dates = [];
    $responsible_users = [];
    $leads = $api->find('leads', ['id' => $leads_ids]);
    foreach ($leads as $lead) {
        $modified_dates[$lead['id']] = $lead['last_modified'];
        $responsible_users[$lead['id']] = $lead['responsible_user_id'];
    }

    // прикрепление существующих в аккаунте контактов к сделкам
    if (count($exist_contacts)) {
        $link_contacts_data = [];
        foreach ($exist_contacts as $lead_id => $contacts_ids) {
            $link_contacts_data_item = [
                'id' => $lead_id,
                'updated_at' => API_Helpers::update_last_modified($modified_dates[$lead_id]),
            ];
            foreach ($contacts_ids as $contact_id) {
                $link_contacts_data_item['contacts_id'][] = $contact_id;
            }
            $link_contacts_data[] = $link_contacts_data_item;
        }
        // var_dump($link_contacts_data);

        $data = ['update' => $link_contacts_data];
        $result = post_request('/api/v2/leads', $data);
        $logger->log(count($result['_embedded']['items']) . ' leads updated');
    }

    // создание и прикрепление контпктов, не существующих в аккаунте
    if (count($no_contacts)) {
        $no_contacts_users = [];
        foreach ($no_contacts as $lead_id => $users_ids) {
            $no_contacts_users = array_merge($no_contacts_users, $users_ids);
        }
        $no_contacts_users = array_unique($no_contacts_users);
//        var_dump($no_contacts_users);die();

        $db_data = [];
        $users_ids = array_map('intval', $no_contacts_users);
        $in = '(' . implode(',', $users_ids) . ')';
        $sql = "SELECT ID, NAME, LAST_NAME, EMAIL, PERSONAL_PHONE FROM b_user WHERE ID IN " . $in;
//        $logger->log($sql);
        $resource = $db->query($sql);
        while ($row = $resource->fetch(FALSE)) {
//            var_dump($row);
            $name = isset($row['NAME']) ? $row['NAME'] : 'Name not specified';
            $name .= isset($row['LAST_NAME']) ? ' ' . $row['LAST_NAME'] : '';
            $db_data[$row['ID']] = [
                'name' => $name,
                'custom_fields' => [
                  ['id' => $phone_field_id, 'values' => [['value' => $row['PERSONAL_PHONE'], 'enum' => 'WORK']]],
                  ['id' => $email_field_id, 'values' => [['value' => $row['EMAIL'], 'enum' => 'WORK']]],
                  ['id' => $user_field_id, 'values' => [['value' => $row['ID']]]]
                ]
            ];
        }
//        var_dump($db_data);

        $create_contacts_data = [];
        $all_users_ids = [];
        foreach ($no_contacts as $lead_id => $users_ids) {
            foreach ($users_ids as $user_id) {
                // если встретятся дубли по user_id - сохраним в отдельный массив, чтобы не создавать одинаковые контакты (потом привяжем)
                if (in_array($user_id, $all_users_ids)) {
                    $doubles_by_user_id[$lead_id][] = $user_id;
                    continue;
                }
                $all_users_ids[] = $user_id;
                $create_contacts_data_item = [
                    'responsible_user_id' => $responsible_users[$lead_id],
                    'leads_id' => [$lead_id]
                ];
                $create_contacts_data_item = array_merge($create_contacts_data_item, $db_data[$user_id]);
                $create_contacts_data[] = $create_contacts_data_item;
            }
        }

//        var_dump($make_contacts_data);

        $data = ['add' => $create_contacts_data];
        $result = post_request('/api/v2/contacts', $data);
        $logger->log(count($result['_embedded']['items']) . ' contacts added to account');
    }

}

$logger->log(count($doubles_by_user_id));

function post_request($link, $data) {
    global $curl;
    global $logger;
    global $files_path;
    $errors_file = $files_path . '/amc_errors_file.txt';
    $request_errors_data = $files_path . '/amc_request_errors_data.txt';

    $link = AMO_DEFAULT_PROTOCOL . '://' . AMO_CUSTOMERS_US_SUBDOMAIN . '.' . (AMO_DEV_MODE ? HOST_DIR_NAME . '.amocrm2.com' : 'amocrm.com') . $link;
    $link .= '?USER_LOGIN=' . CUSTOMERS_API_USER_LOGIN . '&USER_HASH=' . CUSTOMERS_API_USER_HASH;
    $curl->init($link);
    $curl->option(CURLOPT_POSTFIELDS, http_build_query($data));
    $curl->exec();
    $info = $curl->info();
//    var_dump($info);
    $result = json_decode($curl->result(), TRUE);
    $curl->close();
//    var_dump($result);

    $resp_code = $info['http_code'];

    if ($resp_code !== 200 && $resp_code !== 100) {
        write_to_file($errors_file, $info);
        write_to_file($request_errors_data, $data);
        $logger->error('request error. Code ' . $resp_code);
    } elseif (count($result['_embedded']['errors'])) {
        $logger->error('request errors found and logged');
        write_to_file($errors_file, $result['_embedded']['errors']);
        write_to_file($request_errors_data, $data);
    }

    return $result;
}

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
