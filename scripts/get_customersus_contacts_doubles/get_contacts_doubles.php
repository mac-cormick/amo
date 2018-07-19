<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Required;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;

/**
 * Скрипт для поиска "дублей" контактов или сделок
 * по значению кастомных полей контактов(email OR user ID), сделок(account ID)
 * При наличии более 1 сущности с одинаковым значением кастомного поля
 * записывает результат (вида custom_field_value => [$entities_ids]) в файл
 **/

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
		->add(new Optional('count', 'c', 'count of entities getting by one request', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp/amol)', Param::TYPE_STRING))
		->add(new Required('entity', 'e', 'entities type(leads or contacts)', Param::TYPE_STRING))
		->add(new Required('field', 'f', 'custom field\'s id', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$offset = (int)$params->get('offset');
$count = (int)$params->get('count');
$field_id = (int)$params->get('field');
$entity = $params->get('entity');

$offset = ($offset > 0) ? $offset : 0;
$count = ($count > 0) ? $count : 500;
$limit_offset = $offset;
if ($entity !== 'contacts' && $entity !== 'leads') {
	$logger->error('wrong type of entities passed. leads or contacts are allowed');
	die();
} else {
	$entities_type = ($entity === 'contacts') ? 'contacts' : 'leads';
}

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);

if (!$api->auth()) {
	die("Auth error in customers\n");
}

$doubles_file = $files_path . ($entities_type === 'contacts' ? '/cus_contacts_doubles.txt' : '/cus_leads_doubles.txt');

$entities_result = TRUE;
$values_to_check = [];

while ($entities_result) {
	$logger->log('getting entities... OFFSET: ' . $limit_offset);
	$entities_result = $api->find($entities_type, ['limit_rows' => $count, 'limit_offset' => $limit_offset]);
	$limit_offset += $count;

	if (!$entities_result) {
		$logger->log('0 entities received');
		break;
	}

	// Формирование массива вида custom_field_value => [$entities_ids]
	$logger->log('Checking ' . count($entities_result) . ' entities...');
	foreach ($entities_result as $entity) {
		$custom_fields = $entity['custom_fields'];
		foreach ($custom_fields as $custom_field) {
			if ($custom_field['id'] == $field_id) {
				$values = $custom_field['values'];
				foreach ($values as $values_item) {
					$value = trim($values_item['value']);
					$values_to_check[$value][] = $entity['id'];
				}
			}
		}
	}
}

$logger->log('Searching for doubles...');
if (count($values_to_check)) {
	$i = 0;
	foreach ($values_to_check as $value => $entities_ids) {
		if (count($entities_ids) > 1) {
			$i++;
			$data = [$value => $entities_ids];
			file_put_contents($doubles_file, json_encode($data) . "\n", FILE_APPEND);
		}
	}
	$logger->log($i . ' groups of double entities found & wrote to file');
}
