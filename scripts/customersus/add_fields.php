<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Loader\Loader;

$app_path = realpath(dirname(__FILE__) . '/../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
	$params
		->add(new Optional('count', 'c', 'count of entities getting by one request', Param::TYPE_INT))
//		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$count = (int)$params->get('count');

$count = ($count > 0) ? $count : 250;
$offset = 0;

$currency_en_file = $files_path . '/currency-en.json';

$curl = $container['curl'];
$api = new Api_Client(['lang' => 'en'], $curl);
if (!$api->auth()) {
	die("Auth error\n");
}

//Loader::init_all(TRUE);
//
//$currency_ru = CManageCurrency::GetList('SORT', 'ASC', 'ru');
//$currency_en = CManageCurrency::GetList('SORT', 'ASC', 'en');
//var_dump($currency);

$account_info = $api->get_account();
$customers_custom_fields = $account_info['account']['custom_fields']['customers'];
var_dump($custom_fields);die();

$sql = "SELECT CODE, VALUE, LOCALE FROM qcrm_currency WHERE LOCALE='ru' OR LOCALE='en'";
$resource = $db->query($sql);

$currency_en = [];
$currency_ru = [];

while ($row = $resource->fetch(FALSE)) {
	if ($row['LOCALE'] === 'en') {
		$currency_en[$row['CODE']] = $row['VALUE'];
	} else {
		$currency_ru[$row['CODE']] = $row['VALUE'];
	}
}
//var_dump($currency_en);
//var_dump($currency_ru);

