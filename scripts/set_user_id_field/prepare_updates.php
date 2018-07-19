<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Логика скрипта:
 * пройтись по контактам в кастомерс us, получив поле email
 * проверить наличие юзера с таким email
 * записать контакты, по которым было найдено совпадение, в файл для адейта
 **/

$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

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
$update_file = $files_path . '/uid-update-data.txt';
$will_not_update = $files_path . '/uid-willnot-update.json';
$contacts_result = TRUE;
$total_count = 0;
$email_field_id = AMO_DEV_MODE ? 1277144 : 66200;

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
        $email_exists = FALSE;
        $custom_fields = $contact['custom_fields'];
        foreach ($custom_fields as $custom_field) {
            if ((int)$custom_field['id'] === $email_field_id) {
                $email_exists = TRUE;
                foreach ($custom_field['values'] as $value) {
                    $email = trim($value['value']);
                    $contacts_to_check[$contact['id']][] = $email;
                    $emails_to_check[] = $email;
                }
                $unique_emails = array_unique($emails_to_check);
            }
        }
        if (!$email_exists) {
            write_to_file($will_not_update, $contact['id']);
        }
    }

    if (count($unique_emails)) {
        $logger->log(count($contacts_to_check) . ' contacts have E-mail. Others wrote to file. Searching for users...');
        $users = find_users($unique_emails);
        $users = array_map('strtolower', $users); // email не чувствителен к регистру
    }

    if (count($users)) {
        $update_count = 0;
        $wont_update_count = 0;
        foreach ($contacts_to_check as $contact_id => $emails) {
            $emails = array_map('strtolower', $emails);
            $user_exists = FALSE;
            foreach ($emails as $email) {
                if ($user_id = array_search($email, $users)) {
                    $update_data = [$contact_id => $user_id];
                    write_to_file($update_file, $update_data);
                    $user_exists = TRUE;
                    $update_count++;
                    break;
                }
            }
            if (!$user_exists) {
                write_to_file($will_not_update, $contact_id);
                $wont_update_count++;
            }
        }
        $logger->log($update_count . ' contacts wrote to update file');
        $logger->log($wont_update_count . ' contacts will not be updated and wrote to file');
    } else {
        $logger->log('no users with such emails');
    }
}

function find_users($emails) {
    global $db;
    $users = [];
    $values = implode(',', array_fill(0, count($emails), '?'));
    $sql = "SELECT ID, LOGIN FROM b_user WHERE LOGIN IN (" . $values . ")";
    $types = [str_repeat('s', count($emails))];
    $bind_params = array_merge($types, $emails);

    $resource = $db->query($sql, ['prepare' => TRUE, 'bind_params' => $bind_params]);
    while ($row = $resource->fetch(FALSE)) {
        $users[$row['ID']] = $row['LOGIN'];  // массив id юзеров, найденных в таблице
    }
    return $users;
}

function write_to_file($path, $data, $encode = TRUE) {
    if ($encode) {
        $data = json_encode($data);
    }
    file_put_contents($path, $data . "\n", FILE_APPEND);
}
