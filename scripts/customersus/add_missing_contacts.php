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
        ->add(new Required('entity', 'e', 'entity type(leads or customers)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($e->getMessage() . "\n" . $params->get_info());
    die;
}

$files_path = $params->get('dir');
$entity_type = $params->get('entity');
$line = (int)$params->get('line');
$count = (int)$params->get('count');

if ($entity_type !== 'leads' && $entity_type !== 'customers') {
    die("unsupported entity type parameter! leads or customers allowed\n");
}

$line_number = ($line > 0) ? $line : 0;
$count = ($count > 0) ? $count : 250;

$diffs_file = $files_path . '/amc_diffs_file.txt';
$db_no_user = $files_path . '/db_no_user.txt';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error\n");
}

$user_field_id = 1235547;
$phone_field_id = AMO_DEV_MODE ? 1277143 : 66196;
$email_field_id = AMO_DEV_MODE ? 1277144 : 66200;
$contacts_result = TRUE;

$contacts_by_user = contacts_by_user(); // формирование массива соответствий contact_id => user_id
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
    $logger->separator(50);
    $logger->log('diffs file\'s current line number: ' . $lines_count);
    $i++;
    $exist_contacts = [];
    $no_contacts = [];
    $entities_ids = [];
    $logger->log('Checking ' . $count . ' entities...');
    for ($x=0; $x<$count; $x++) {
        $update_str = fgets($diffs_file_open);
        if (!$update_str) {
            fclose($diffs_file_open);
            $logger->log('End of file');
            $line_get_result = FALSE;
            break;
        }

        $update_item = json_decode($update_str, TRUE);
        foreach ($update_item as $entity_id => $users_ids) {
            $entities_ids[] = $entity_id;
            foreach ($users_ids as $user_id) {
                if ($contact_id = array_search($user_id, $contacts_by_user)) {
                    $exist_contacts[$entity_id][] = $contact_id; // контакт найден в аккаунте
                } else {
                    $no_contacts[$entity_id][] = $user_id; // нет контакта в аккаунте, необходимо создать
                }
            }
        }
    }
    $logger->log(count($exist_contacts) . ' entities will be updated by adding existing contacts');
    $logger->log(count($no_contacts) . ' entities will be updated by adding new contacts');

    // прикрепление существующих в аккаунте контактов к сделкам
    if (count($exist_contacts)) {
        $result = link_exist_contacts($exist_contacts, $entity_type);
        $logger->log(count($result['_embedded']['items']) . ' entities updated');
    }

    // создание и прикрепление контактов, не существующих в аккаунте
    if (count($no_contacts)) {
        $no_contacts_users = [];
        foreach ($no_contacts as $entity_id => $users_ids) {
            $no_contacts_users = array_merge($no_contacts_users, $users_ids);
        }
        $no_contacts_users = array_unique($no_contacts_users);

        $db_data = [];
        $users_ids = array_map('intval', $no_contacts_users);
        $in = '(' . implode(',', $users_ids) . ')';
        $sql = "SELECT ID, NAME, LAST_NAME, EMAIL, PERSONAL_PHONE FROM b_user WHERE ID IN " . $in;
        $resource = $db->query($sql);
        while ($row = $resource->fetch(FALSE)) {
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

        $create_contacts_data = [];
        $all_users_ids = [];
        foreach ($no_contacts as $entity_id => $users_ids) {
            $entities_ids = array_keys($no_contacts);
            $entities_info = get_entities_info($entities_ids, $entity_type);
            $responsible_users = $entities_info['responsible_users'];

            foreach ($users_ids as $user_id) {
                // если встретятся дубли по user_id - сохраним в отдельный массив, чтобы не создавать одинаковые контакты (потом привяжем)
                if (in_array($user_id, $all_users_ids)) {
                    $doubles_by_user_id[$entity_id][] = $user_id;
                    continue;
                }
                $all_users_ids[] = $user_id;
                $create_contacts_data_item = [
                    'responsible_user_id' => $responsible_users[$entity_id],
                    $entity_type . '_id' => [$entity_id]
                ];
                if ($db_data[$user_id]) {
                    $create_contacts_data_item = array_merge($create_contacts_data_item, $db_data[$user_id]);
                    $create_contacts_data[] = $create_contacts_data_item;
                } else {
                    write_to_file($db_no_user, $user_id, FALSE);
                }
            }
        }
        $logger->log(count($doubles_by_user_id) . ' entities have repeating users & will be updated later');

        if (count($create_contacts_data)) {
            $data = ['add' => $create_contacts_data];
            $result = post_request('/api/v2/contacts', $data);
            $logger->log(count($result['_embedded']['items']) . ' contacts added to account');
        }
    }

}

$logger->separator(100);

if (count($doubles_by_user_id)) {
    $contacts_by_user = contacts_by_user();  // обновляем с учетом созданных контактов
    $logger->log('adding contacts to ' . count($doubles_by_user_id) . ' entities with repeating users...');

    $update_entities = [];
    foreach ($doubles_by_user_id as $entity_id => $users_ids) {
        foreach ($users_ids as $user_id) {
            $update_entities[$entity_id][] = array_search($user_id, $contacts_by_user);
        }
    }

    $update_entities_chunks = array_chunk($update_entities, $count, TRUE);
    foreach ($update_entities_chunks as $update_entities_chunk) {
        $result = link_exist_contacts($update_entities_chunk, $entity_type);
        $logger->log(count($result['_embedded']['items']) . ' entities updated');
    }
}

function contacts_by_user() {
    global $api;
    global $user_field_id;
    global $logger;
    $contacts_by_user = [];
    $i = 0;
    $contacts_count = 250;

    do {
        $contacts_result = $api->find('contacts', ['limit_rows' => $contacts_count, 'limit_offset' => $i * $contacts_count]);

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

    return $contacts_by_user;
}

function link_exist_contacts($items, $entity_type) {
    $link_contacts_data = [];
    $entities_ids = array_keys($items);

    $entities_info = get_entities_info($entities_ids, $entity_type);
    $modified_dates = $entities_info['modified_dates'];

    foreach ($items as $entity_id => $contacts_ids) {
        $link_contacts_data_item = [
            'id' => $entity_id,
            'updated_at' => API_Helpers::update_last_modified($modified_dates[$entity_id]),
        ];
        foreach ($contacts_ids as $contact_id) {
            $link_contacts_data_item['contacts_id'][] = $contact_id;
        }
        $link_contacts_data[] = $link_contacts_data_item;
    }

    $data = ['update' => $link_contacts_data];
    $result = post_request('/api/v2/' . $entity_type, $data);

    return $result;
}

function get_entities_info($entities_ids, $entity_type) {
    global $api;
    $modified_dates = [];
    $responsible_users = [];

    $entities = $api->find($entity_type, ['id' => $entities_ids]);

    foreach ($entities as $entity) {
        $modified_dates[$entity['id']] = $entity['last_modified'];
        $responsible_users[$entity['id']] = $entity['responsible_user_id'];
    }

    $entities_info = [
        'modified_dates' => $modified_dates,
        'responsible_users' => $responsible_users
    ];

    return $entities_info;
}

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
    $result = json_decode($curl->result(), TRUE);
    $curl->close();

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
