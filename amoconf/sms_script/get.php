<?php
/**
 * Created by PhpStorm.
 * Date: 28.09.18
 * Time: 13:26
 * Скрипт проверяет есть ли "SMS Code" в контакте, если нет, то добавляет
 */

if (PHP_SAPI !== 'cli') {
    die;
}

require_once __DIR__ . '/../../../bootstrap.php';

use Classes\User;
use Classes\Api_client\AmoAPI;

require_once 'config.php';

$user = new User($amo_login, $amo_hash, $amo_subdomain, $amo_domain);
$amoAPI = new AmoAPI($user);
$offset = 0;
$count_contacts_with_code = 0;
$count_contacts_without_code = 0;
$all_contacts = [];
while (TRUE) {
    $data = [
        'limit_rows' => 10,
        'limit_offset' => $offset
    ];
    $query = http_build_query($data);
    $contacts = $amoAPI->find_all_entities('contacts', $query);
    if (empty($contacts)) {
        break;
    }
//    var_dump(array_column($contact['custom_fields'], 'id'));

    foreach ($contacts as $contact) {
        $f_id = array_column($contact['custom_fields'], 'id');
        if ($f_id !== []){
            if ( in_array($SMSCode, $f_id)) {
                echo "У контакта ".$contact['name']." id ".$contact['id']." Присутствует SMS Code\n";
                $count_contacts_with_code++;
            }
        } else {
            echo "У контакта ".$contact['name']." отсутствует SMS Code\n";
            $count_contacts_without_code++;
            $all_contacts[] = [
                'id' => $contact['id'],
                'name' => $contact['name'],
                'last_modified' => $contact['updated_at'],
            ];
        }
    }
    $offset += 10;
    usleep(500000);

}
echo "СМС код есть у $count_contacts_with_code , нет у $count_contacts_without_code";
$all_contacts = json_encode($all_contacts);

file_put_contents('array_contacts.txt', $all_contacts);
