<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Логика скрипта:
 * пройтись по сделкам в кастомерс, выделив цифровую часть из имен сделок
 * проверить наличие аккаунта с таким ID
 * записать сделки, по которым было найдено совпадение, в файл для адейта
 * пройтись по остальным сделкам, повторив процедуру по именам прикрепленных компаний
 *
 **/

$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];
$curl = $container['curl'];

$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
    die("Auth error in customers\n");
}

$params = new \Cli\Params\CLI_Params();

try {
    $params
        ->add(new Optional('offset', 'o', 'limit_offset parameter', Param::TYPE_INT))
        ->add(new Optional('count', 'c', 'count of contacts getting by one request', Param::TYPE_INT))
        ->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp/amol)', Param::TYPE_STRING))
        ->init();
} catch (Params_Validation_Exception $e) {
    $logger->error($params->get_info());
    die;
}

$files_path = $params->get('dir');
$offset = (int)$params->get('offset');
$count = (int)$params->get('count');

$offset = ($offset > 0) ? $offset : 0;
$count = ($count > 0) ? $count : 500;
$limit_offset = $offset;

// Формирование файлов для апдейта
$update_file = $files_path . '/update-data.txt';
$will_not_update = $files_path . '/willnot-update.json';

$contacts_result = true;
$total_count = 0;

while ($contacts_result) {
    $contacts_to_check = [];
    $emails_to_check = [];
    $logger->log("getting contacts... OFFSET: {$limit_offset}");
    $contacts_result = $api->find('contacts', ['limit_rows' => $count, 'limit_offset' => $limit_offset]);
    $limit_offset += $count;
    if (!$contacts_result) {
        $logger->log('0 contacts received');
        break;
    }
    $logger->log('Checking ' . count($contacts_result) . ' contacts...');

    foreach ($contacts_result as $contact) {
        $email_exists = false;
        $custom_fields = $contact['custom_fields'];
        foreach ($custom_fields as $custom_field) {
            if ((int)$custom_field['id'] === 1277144) {
                $email = trim($custom_field['values'][0]['value']);
                $email_exists = true;
                $contacts_to_check[$contact['id']] = $email;
                $emails_to_check[] = "'".$email."'"; // в кавычки - для sql запроса
            }
        }
        if (!$email_exists) {
            write_to_file($will_not_update, $contact['id']);
        }
    }

    if (count($emails_to_check)) {
        $logger->log(count($emails_to_check) . ' contacts have E-mail. Others wrote to file. Searching for users...');
        $users = find_users($emails_to_check);
    }

    if (count($users)) {
        $logger->log(count($users) . ' users found with such emails');
        $contacts_to_update = array_intersect($contacts_to_check, $users);
        $other_contacts = array_diff($contacts_to_check, $users);
        if (count($contacts_to_update)) {
            $logger->log(count($contacts_to_update) . ' contacts wrote to update file');
            $total_count += count($contacts_to_update);
            $logger->log($total_count . ' CONTACTS WILL BE UPDATED');
            foreach ($contacts_to_update as $id => $email) {
                $update_data = [$id => array_search($email, $users)]; // id контакта => id юзера
                write_to_file($update_file, $update_data);
            }
        } else {
            $logger->log('no matches found by emails');
        }
        if (count($other_contacts)) {
            foreach ($other_contacts as $id => $email) {
                write_to_file($will_not_update, $id);
            }
            $logger->log(count($other_contacts) . ' contacts will not be updated and wrote to file');
        }
    } else {
        $logger->log('no users with such emails');
    }
}

function find_users($emails) {
    global $db;
    $users = [];
    $str = implode(' OR LOGIN LIKE ', $emails);
    $sql = "SELECT ID, LOGIN FROM b_user WHERE LOGIN LIKE " . $str;
    $resource = $db->query($sql);
    while ($row = $resource->fetch(false)) {
        $users[$row['ID']] = $row['LOGIN'];  // массив id юзеров, найденных в таблице
    }
    return $users;
}

function write_to_file($path, $data, $encode=true) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
