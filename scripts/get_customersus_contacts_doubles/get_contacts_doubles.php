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
$api = new Api_Client(['lang' => $lang], $curl);

if (!$api->auth()) {
	die("Auth error in customers\n");
}

// Формирование файла дублей контактов
$doubles_file = $files_path . '/ccd_doubles.txt';

$contacts_result = true;
$email_field_id = AMO_DEV_MODE ? 1277144 : 66200;
$contacts_to_check = [];

while ($contacts_result) {
	$emails_to_check = [];
	$logger->log('getting contacts... OFFSET: ' . $limit_offset);
	$contacts_result = $api->find('contacts', ['limit_rows' => $count, 'limit_offset' => $limit_offset]);
	$limit_offset += $count;

	if (!$contacts_result) {
		$logger->log('0 contacts received');
		break;
	}

	$logger->log('Checking ' . count($contacts_result) . ' contacts...');
	foreach ($contacts_result as $contact) {
		$email_exists = false;
		$email_field = $api->get_cf_values($contact, NULL, $email_field_id);
		if (is_array($email_field)) {
			$email = trim($email_field['value']);
			$email_exists = true;
			$contacts_to_check[$contact['id']] = $email;
		}
		if (!$email_exists) {
			write_to_file($will_not_update, $contact['id']);
		}
	}
}

$doubles_result = [];

if ($count = count($contacts_to_check)) {
	$logger->log($count . ' contacts have E-mail. Others wrote to file.');
	$contacts_by_email = array_count_values($contacts_to_check);  // Формирование массива элементов вида email => number_of_contacts_with_suc_email
	foreach ($contacts_by_email as $email => $count) {
		if ($count > 1) {
			$doubles_keys = array_keys($contacts_to_check, $email); // id всех контактов в $contacts_to_check, меющих текущий email
			$doubles_result[$email] = $doubles_keys;
		}
	}
//	var_dump($doubles_result);
	foreach ($doubles_result as $email => $ids) {
		$data = [$email => $ids];
		write_to_file($doubles_file, $data);
	}
}

function write_to_file($path, $data, $encode=true) {
	if ($encode) {
		$data = json_encode($data);
	}
	file_put_contents($path, $data . "\n", FILE_APPEND);
}
