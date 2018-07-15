<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

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

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);

if (!$api->auth()) {
    die("Auth error in customers\n");
}

// Формирование файла дублей контактов
$doubles_file = $files_path . '/ccd_doubles.txt';

$contacts_result = true;
$email_field_id = AMO_DEV_MODE ? 1277144 : 66200;
$emails_to_check = [];

while ($contacts_result) {
    $logger->log('getting contacts... OFFSET: ' . $limit_offset);
    $contacts_result = $api->find('contacts', ['limit_rows' => $count, 'limit_offset' => $limit_offset]);
    $limit_offset += $count;

    if (!$contacts_result) {
        $logger->log('0 contacts received');
        break;
    }

    $logger->log('Checking ' . count($contacts_result) . ' contacts...');
    foreach ($contacts_result as $contact) {
        $custom_fields = $contact['custom_fields'];
        foreach ($custom_fields as $custom_field) {
            if ($custom_field['id'] == $email_field_id) {
                $emails = $custom_field['values'];
                foreach ($emails as $email_item) {
                    $email = trim($email_item['value']);
                    $emails_to_check[$email][] = $contact['id'];
                }
            }
        }
    }
}

if (count($emails_to_check)) {
    $i = 0;
    foreach ($emails_to_check as $email => $contacts_ids) {
        if (count($contacts_ids) > 1) {
            $i++;
            $data = [$email => $contacts_ids];
            echo $doubles_file;
            $res = file_put_contents($doubles_file, json_encode($data) . "\n", FILE_APPEND);
        }
    }
    $logger->log($i . ' groups of double contacts found & wrote to file');
}
