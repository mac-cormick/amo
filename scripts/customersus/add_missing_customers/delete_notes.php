<?php

use Cli\Helpers\Api_Client;
use Helpers\Pimple;
use Cli\Params\Types\Required;
use Cli\Params\Types\Optional;
use Cli\Params\Types\Param;
use Cli\Params\Exceptions\Params_Validation_Exception;
use Cli\Scripts\Single\Customersus\Helpers\Account_Helpers;
use Cli\Loader\Loader;

/**
 * Логика скрипта:
 * Добавить в сделки, полуенные из файла примечание типа CALL IN
 * в аккаунте добавятся покупатели по событию входящего звонка(при настройке в воронке)
 */

$app_path = realpath(dirname(__FILE__) . '/../../../../../..');
require_once $app_path . '/app/bootstrap.php';

define('CLI_SCRIPT_MAX_EXEC', 3 * HOUR);

Loader::init_all(FALSE, AMO_CUSTOMERSUS_ACCOUNT_ID);

$container = Pimple::instance();
$logger = $container['logger'];
$db = $container['db_cluster'];

$params = new \Cli\Params\CLI_Params();
try {
	$params
		->add(new Optional('line', 'l', 'notes file\'s current cycle line number', Param::TYPE_INT))
		->add(new Required('dir', 'd', 'path to files\' dir (examp /tmp)', Param::TYPE_STRING))
		->init();
} catch (Params_Validation_Exception $e) {
	$logger->error($e->getMessage() . "\n" . $params->get_info());
	die;
}

$files_path = $params->get('dir');
$line = (int)$params->get('line');

$line_number = ($line > 0) ? $line : 0;

$notes_file = $files_path . '/amc_added_notes.txt';

//$curl = $container['curl'];
//$api = new Api_Client(['lang' => 'en'], $curl);
//if (!$api->auth()) {
//	die("Auth error\n");
//}

$helper = new Account_Helpers($logger, $api, NULL, $files_path);

$file_handle = fopen($notes_file, 'rt');
if (!$file_handle) {
	$logger->error('Opening file error');
	die();
}
if ($line > 0) {
	while (!feof($file_handle) && $line--) {
		fgets($file_handle);
	}
}

$data_get_result = TRUE;
$notes_ids = [];
$max = 10000; // максимальное количество удаляемых за раз строк

while ($data_get_result) {
	// в одной строке максимум 500 примечаний, читаем по 1 строке, пока не дойдем до $max
	do {
		$notes_chunk = $helper->get_file_content($file_handle, 1);
		$notes_ids = array_merge($notes_ids, $notes_chunk);
		if (!$notes_chunk) {
			$data_get_result = FALSE;
			break;
		}
	} while (count($notes_ids) < 10000);

	if (!empty($response['response']['notes']['add'])) {
		sleep(3);
		$added_notes = $response['response']['notes']['add'];
		$notes_ids = array_column($added_notes, 'id');
		$logger->log('Deleting ' . count($notes_ids) . ' CALL IN notes...');

		$ids = array_map('intval', $notes_ids);
		$helper->write_to_file($added_notes_file, $ids); // на случай если что-то не удалится
		$in = '(' . implode(',', $ids) . ')';

		$sql = "DELETE FROM qcrm_notes WHERE ACCOUNT_ID=" . AMO_CUSTOMERSUS_ACCOUNT_ID . " AND ID IN " . $in;
		$db->query($sql);
	} else {
		$helper->write_to_file($calls_add_errors_file, ['data' => $calls_add_data, 'response' => $response]);
	}
}
