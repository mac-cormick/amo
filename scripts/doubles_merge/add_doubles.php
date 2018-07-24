<?php
use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
$app_path = realpath(dirname(__FILE__) . '/../../../..');
require_once $app_path . '/bootstrap.php';
define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);
$container = Pimple::instance();
$logger = $container['logger'];
$params = new \Cli\Params\CLI_Params();
try {
	$params
		->add(new Optional('count', 'c', 'count of contacts to create', Param::TYPE_INT))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}
$count = $params->get('cont');
$curl = $container['curl'];
$api = new Api_Client(['lang' => 'ru'], $curl);
$api->set_auth_data('testcust1', 'amolyakov@team.amocrm.com', '58f4358fa880dba135f7a9710d6b9894e43cadc8');
if (!$api->auth()) {
	die("Auth error in customers\n");
}
$logger->log('Start');
$logger->separator();
//for ($x=0; $x<500; $x++) {
//	$data[] = ['name' => md5(rand())];
//}
//$leads = $api->add('leads', $data);
//var_dump($leads);die();
$leads = $api->find('leads', ['responsible_user_id' => 958030]);
$leads_ids = array_column($leads, 'id');
$position = ['director', 'директор', 'manager', 'designer', 'дизайнер', 'project-manager'];
$country = ['USA', 'США', 'Russia', 'Spain', 'Canada', 'Россия'];
$state = ['Colorado', 'Texas', 'Moscow', 'Москва', 'Madrid'];
$user = [11111, 12345, 54321, 22222, 9876543, 8765432, 7654321, 654321678, 66666, 555555, 7777777, 88888888, 999999999, 3723700, 3723701, 3723702, 3723703, 3723704, 3723705, 3723706, 3723707, 3723708, 3723709, 3720453, 3720454, 3720455, 3720456, 3720457, 3720458];
$roles = [3723700, 3723701, 3723702, 3723703, 3723704];
$familiarity = [3723705, 3723706, 3723707, 3723708, 3723709];
$ims = [3720453, 3720454, 3720455, 3720456, 3720457, 3720458];
for ($i=0; $i<2; $i++) {
	$logger->log('Adding... ' . $i);
	$data = [];
	for ($x=0; $x<500; $x++) {
		$leads_id = [];
		$fields_arr = [];
		for ($z=0; $z<rand(1,5); $z++) {
			$key = array_rand($leads_ids);
			$leads_id[] = (int)$leads_ids[$key];
		}
//		var_dump($leads_id);
		$pos_key = array_rand($position);
		$country_key = array_rand($country);
		$state_key = array_rand($state);
		$user_key = array_rand($user);
		$role = array_rand($roles);
		$famil = array_rand($familiarity);
		$comp = [md5(rand()), null];
		$company_name = array_rand($comp);
		$fields = [
			['id' => 4417759, 'values' => [['value' => $position[$pos_key]]]],
			['id' => 4419201, 'values' => [['value' => rand(0,1)]]],
			['id' => 4419276, 'values' => [['value' => rand(0,1)]]],
			['id' => 4419202, 'values' => [['value' => $country[$country_key]]]],
			['id' => 4419203, 'values' => [['value' => $state[$state_key]]]],
			['id' => 4419204, 'values' => [['value' => $user[$user_key]]]],
			['id' => 4417760, 'values' => [['value' => rand(84950000000, 84959999999), 'enum' => 3720444], ['value' => rand(89000000000, 89999999999), 'enum' => 3720446]]],
			['id' => 4417761, 'values' => [['value' => md5(rand()).'@test.ru', 'enum' => 3720450], ['value' => md5(rand()).'@test.ru', 'enum' => 3720451]]],
			['id' => 4417763, 'values' => [['value' => md5(rand()), 'enum' => $ims[array_rand($ims)]], ['value' => md5(rand()), 'enum' => $ims[array_rand($ims)]], ['value' => md5(rand()), 'enum' => $ims[array_rand($ims)]]]],
			['id' => 4419254, 'values' => [['value' => $roles[$role]]]],
			['id' => 4419255, 'values' => [['value' => $familiarity[$famil]]]],
			['id' => 4419275, 'values' => [['value' => md5(rand())]]],
			['id' => 4419277, 'values' => [['value' => rand(901, 999)]]]
		];
		$fields_keys = array_rand($fields, 5);
		foreach ($fields_keys as $key) {
			$fields_arr[] = $fields[$key];
		}
		$data[] = [
			'name' => md5(rand()),
			'linked_leads_id' => $leads_id,
			'company_name' => $comp[$company_name],
			'custom_fields' => $fields_arr
		];
	}
//	var_dump($data);
	$api->add('contacts', $data);
	$response = $api->get_response();
	var_dump($response);
	$logger->log('Added');
}
