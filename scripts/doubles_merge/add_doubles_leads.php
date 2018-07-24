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
$contacts = $api->find('contacts', ['responsible_user_id' => 958030]);
$contacts_ids = array_column($contacts, 'id');

$companies = $api->find('companies', ['responsible_user_id' => 958030]);
$companies_ids = array_column($companies, 'id');

$tags_arr = ['asdf', 'asdfasd132421', '23452345', 'asdf asd fa', '23452345sdgf', '346yetge', '0987', '34256', 'bcxbc', 'htedgf', '245twerg'];
$accounts = [11111, 12345, 54321, 22222, 9876543, 8765432, 7654321, 654321678, 66666, 555555, 7777777, 88888888, 999999999, 3723700, 3723701, 3723702, 3723703, 3723704, 3723705, 3723706, 3723707, 3723708, 3723709, 3720453, 3720454, 3720455, 3720456, 3720457, 3720458];
$pipelines = [12023, 12332];
$voronka_statuses = [142, 143, 17022704, 17022705, 17022706, 17022707];
$dubli_statuses = [142, 143, 17025322, 17025323, 17025324];

for ($i=0; $i<25; $i++) {
	$logger->log('Adding... ' . $i);
	$data = [];
	$data_all = [];
	for ($x=0; $x<30; $x++) {
		$fields = [];
		$contacts_id = [];
		$fields_arr = [];
		$contacts_id = [];
		$tags = [];
		for ($z=0; $z<rand(0,5); $z++) {
			$key = array_rand($contacts_ids);
			$contacts_id[] = (int)$contacts_ids[$key];
		}

		$tag_keys = array_rand($tags_arr, rand(2,5));
		foreach ($tag_keys as $key) {
			$tags[] = $tags_arr[$key];
		}

		$key = array_rand($companies_ids);
		$company_id = (int)$companies_ids[$key];

		$timestamp = rand(time(), 1563888904);
		$date = date('Y/m/d', $timestamp);
		$timestamp_exp = rand(time(), 1563888904);
		$date_exp = date('Y/m/d h:i', $timestamp_exp);

		$adv_channels = [3724312,3724313,3724314,3724315,3724316,3724317];
		$adv_channels_keys = array_rand($adv_channels, rand(2,6));
		$adv_chann = [];
		foreach($adv_channels_keys as $key) {
			$adv_chann[] = $adv_channels[$key];
		}

		$requirements = [3724318,3724319,3724320,3724321,3724322,3724323,3724324,3724325,3724326,3724327,3724328,3724329];
		$requirements_keys = array_rand($requirements, rand(2,12));
		$requirmnts = [];
		foreach($requirements_keys as $key) {
			$requirmnts[] = $requirements[$key];
		}

		$crm_testing = [3724330,3724331,3724332,3724333,3724334,3724335,3724336,3724337];
		$crm_testing_keys = array_rand($crm_testing, rand(2,8));
		$crmtest = [];
		foreach($crm_testing_keys as $key) {
			$crmtest[] = $crm_testing[$key];
		}

		$trouble_some = [3724350,3724351,3724352,3724353];
		$trouble_some_keys = array_rand($trouble_some, rand(2,4));
		$trblsm = [];
		foreach($trouble_some_keys as $key) {
			$trblsm[] = $trouble_some[$key];
		}

		$fields = [
			['id' => 4419490, 'values' => [['value' => rand(3724269, 3724278)]]], // decline reason
			['id' => 4419491, 'values' => [['value' => rand(3724279, 3724287)]]],
			['id' => 4419492, 'values' => [['value' => rand(3724288, 3724290)]]], // language
			['id' => 4419493, 'values' => [['value' => $date]]],
			['id' => 4419494, 'values' => [['value' => rand(3724291, 3724297)]]],
			['id' => 4419495, 'values' => [['value' => rand(3724298, 3724299)]]],
			['id' => 4419497, 'values' => [['value' => rand(3724300, 3724309)]]],
			['id' => 4419498, 'values' => [['value' => rand(2, 199)]]],
			['id' => 4419513, 'values' => [['value' => rand(2, 199)]]],
			['id' => 4419514, 'values' => [['value' => rand(200, 1999999)]]],
			['id' => 4419516, 'values' => [['value' => rand(2, 20)]]],
			['id' => 4419499, 'values' => [['value' => md5(rand())]]],
			['id' => 4419500, 'values' => [['value' => md5(rand())]]],
			['id' => 4419504, 'values' => [['value' => md5(rand())]]],
			['id' => 4419505, 'values' => [['value' => md5(rand())]]],
			['id' => 4417764, 'values' => [['value' => md5(rand())]]],
			['id' => 4419510, 'values' => [['value' => md5(rand())]]],
			['id' => 4419521, 'values' => [['value' => md5(rand())]]],
			['id' => 4419522, 'values' => [['value' => (rand(0,1))]]],
			['id' => 4419523, 'values' => [['value' => (rand(0,1))]]],
			['id' => 4419524, 'values' => [['value' => md5(rand())]]],
			['id' => 4419525, 'values' => [['value' => md5(rand())]]],
			['id' => 4419526, 'values' => [['value' => md5(rand())]]],
			['id' => 4419527, 'values' => [['value' => md5(rand())]]],
			['id' => 4419529, 'values' => [['value' => md5(rand())]]],
			['id' => 4419530, 'values' => [['value' => md5(rand())]]],
			['id' => 4419532, 'values' => [['value' => md5(rand())]]],
			['id' => 4419533, 'values' => [['value' => md5(rand())]]],
			['id' => 4419501, 'values' => [['value' => rand(3724310, 3724311)]]],
			['id' => 4419502, 'values' => $adv_chann],
			['id' => 4419503, 'values' => $requirmnts],
			['id' => 4419506, 'values' => $crmtest],
			['id' => 4419512, 'values' => $trblsm],
			['id' => 4419507, 'values' => [['value' => rand(3724338, 3724341)]]],
			['id' => 4419508, 'values' => [['value' => $date_exp]]],
			['id' => 4419509, 'values' => [['value' => rand(3724342, 3724347)]]],
			['id' => 4419511, 'values' => [['value' => rand(3724348, 3724349)]]],
			['id' => 4419518, 'values' => [['value' => rand(3724354, 3724355)]]],
			['id' => 4419519, 'values' => [['value' => rand(3724356, 3724358)]]],
			['id' => 4419520, 'values' => [['value' => rand(3724359, 3724367)]]],
			['id' => 4419528, 'values' => [['value' => rand(3724368, 3724371)]]],
			['id' => 4419536, 'values' => [['value' => rand(3724379, 3724383)]]],
			['id' => 4419540, 'values' => [['value' => md5(rand()).'@'.md5(rand()).'com']]]
		];
		$acc_key = array_rand($accounts);
		$fields[] = ['id' => 4419544, 'values' => [['value' => $accounts[$acc_key]]]];
		$fields_keys = array_rand($fields, 25);
		foreach ($fields_keys as $key) {
			$fields_arr[] = $fields[$key];
		}
		$pipeline_key = array_rand($pipelines);
		$pipeline = $pipelines[$pipeline_key];

		if ($pipeline == 12023) {
			$status_key = array_rand($voronka_statuses);
			$status = $voronka_statuses[$status_key];
		} else {
			$status_key = array_rand($dubli_statuses);
			$status = $dubli_statuses[$status_key];
		}

		$data[] = [
			'name' => md5(rand()),
			'status_id' => $status,
			'pipeline_id' => $pipeline,
			'contacts_id' => $contacts_id,
			'company_id' => $company_id,
			'tags' => implode(',', $tags),
			'custom_fields' => $fields_arr
		];
		$data_all = ['add' => $data];
	}
//	var_dump($data);
//	$api->add('leads', $data);
//	$response = $api->get_response();
//	var_dump($response);

	$link = "http://testcust1.amolyakov.amocrm2.saas/api/v2/leads?USER_LOGIN=amolyakov@team.amocrm.com&USER_HASH=58f4358fa880dba135f7a9710d6b9894e43cadc8";

	$headers[] = "Accept: application/json";

	//Curl options
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data_all));
	curl_setopt($curl, CURLOPT_URL, $link);
	curl_setopt($curl, CURLOPT_HEADER,false);
	$out = curl_exec($curl);
	curl_close($curl);
	$result = json_decode($out,TRUE);
//	var_dump($result);

	$logger->log('Added');
	sleep(1);

	$added_leads = $result['_embedded']['items'];
	$tasks_data = [];
	$notes_data = [];
	foreach ($added_leads as $lead) {
		for ($n=0; $n<rand(1,3); $n++) {
			$tasks_data[] = [
				'element_id' => $lead['id'],
				'element_type' => 2,
				'complete_till' => rand(time(), time() + 3600000),
				'task_type' => rand(1, 3),
				'text' => md5(rand())
			];
		}
		for ($m=0; $m<rand(1,3); $m++) {
			$notes_data[] = [
				'element_id'    => $lead['id'],
				'element_type'  => 2,
				'task_type'     => rand(1,5),
				'text'          => md5(rand())
			];
		}
	}

	$tasks_data_req = ['add' => $tasks_data];
//	var_dump($tasks_data_req);
	$link = "http://testcust1.amolyakov.amocrm2.saas/api/v2/tasks?USER_LOGIN=amolyakov@team.amocrm.com&USER_HASH=58f4358fa880dba135f7a9710d6b9894e43cadc8";

	//Curl options
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($tasks_data_req));
	curl_setopt($curl, CURLOPT_URL, $link);
	curl_setopt($curl, CURLOPT_HEADER,false);
	$out = curl_exec($curl);
	curl_close($curl);
	$result = json_decode($out,TRUE);
	var_dump(array_column($result['_embedded']['items'], 'id'));
	sleep(1);

	$notes_data_req = ['add' => $notes_data];
	$link = "http://testcust1.amolyakov.amocrm2.saas/api/v2/notes?USER_LOGIN=amolyakov@team.amocrm.com&USER_HASH=58f4358fa880dba135f7a9710d6b9894e43cadc8";

	//Curl options
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($notes_data_req));
	curl_setopt($curl, CURLOPT_URL, $link);
	curl_setopt($curl, CURLOPT_HEADER,false);
	$out = curl_exec($curl);
	curl_close($curl);
	$result = json_decode($out,TRUE);
	var_dump(array_column($result['_embedded']['items'], 'id'));
}
