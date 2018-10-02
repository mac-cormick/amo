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
$all_contacts = json_decode(file_get_contents('array_contacts.txt'), true);

file_put_contents('error_id.txt', '');

foreach ($all_contacts as $contact) {

    $code = mt_rand(1000, 9999);

    $last_modified = $contact['last_modified'] > time() ? $contact['last_modified'] + 1 : time() + 1;
    $contact_for_update[0] = [];

    $contact_for_update[0] = [
        'id' => $contact['id'],
        'last_modified' => $last_modified,
        'custom_fields' => [
            [
                'id' => $SMSCode,
                'values' => [
                    [
                        'value' => $code
                    ]
                ]
            ]
        ]
    ];
    $answer = $amoAPI->update_entities($contact_for_update, CONTACTS_TYPE_STR);
    if (!isset($answer['errors'])) {
        if ($last_modified === $answer[0]['last_modified']) {
            echo "В контакт ".$contact['name']." id = ".$contact['id']." добавлен SMS Code\n";
        }
    }else {
        echo "ОШИБКА!!! В контакт ".$contact['name']." id = ".$contact['id']." НЕ добавлен SMS Code\n";
        file_put_contents('error_id.txt', "Ошибка для id ".$contact['id']." \"".$answer['errors'][$contact['id']]."\"\n", 8);
    }

    usleep(500000);
}

