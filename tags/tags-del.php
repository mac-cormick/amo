<?php

ini_set('max_execution_time', 1800);

require_once "const.php";
require_once 'functions.php';

$subdomain = 'newdemonew';
$login = 'amolyakov@team.amocrm.com';
$hash = '691c2c8c35794e95be679e7a21d40c4';
$tags_names = ['tag', 'tag1', 'no-tag']; // Теги к удалению

// Авторизация
$result = auth($subdomain, $login, $hash);

if ($result === TRUE) {
	$rows = 500;
	$offset = 500;
	$i = 0;

    $updates_size = make_updates_files($subdomain, $rows, $offset, $tags_names); // Запись данных для запросов апдейта сделок и добавления примечаний в json файлы
	if ($updates_size > 0) {
		// Получение массивов для апдейта из json файлов
		$files_dir = APP_DIR."/files";
		$update_files = array_diff(scandir($files_dir), array('..', '.')); // Файлы с массивами для апдейта и примечаний
		foreach ($update_files as $update_file) {
			$copy_file = false;
			$i++;
			sleep(1);
			$json_string = file_get_contents($files_dir.'/'.$update_file);
			$updates_array = json_decode($json_string, true);

			// Удаление тегов и добавление примечаний
			foreach ($updates_array as $updates_array_item) {
				$leads_update_array = [];
				foreach ($updates_array_item[0] as $item) {
					$item['updated_at'] = time(); // Дозапись параметра updated_at в массив для апдейта
					$leads_update_array[] = $item;
				}
				$leads_data = array(
					'update' => $leads_update_array,
				);
				$leads_result = del_tags($subdomain, $leads_data);
//				var_dump($leads_result);
				if (!is_array($leads_result)) {
					// При ошибке запроса пишем номер запроса и ошибки, пропускаем добавление примечаний
					$leads_update_errors_put_result_txt = file_put_contents(APP_DIR."/errors/leads-update-errors.txt", $i . ' - ' . $leads_result . "\n", FILE_APPEND);
					echo $i . " Ошибка при апдейте сделок - " . $leads_result . "\n";
					continue;
				}
				if ($leads_result_errors = $leads_result['_embedded']['errors']) {
					// При неудачном апдейте пишем лог ошибок данной итерации, пропускаем добавление примечаний
					$leads_update_errors_put_result_json = file_put_contents(APP_DIR."/errors/leads-update-errors.json", json_encode($leads_result_errors), FILE_APPEND);
					continue;
				}
				$notes_data = array(
					'add' => $updates_array_item[1],
				);
				$notes_result = notes_add($subdomain, $notes_data);
				if (!is_array($notes_result)) {
					// При ошибке запроса пишем номер запроса и ошибки, пропускаем добавление примечаний
					$leads_update_errors_put_result_txt = file_put_contents(APP_DIR."/errors/notes-update-errors.txt", $i . ' - ' . $notes_result . "\n", FILE_APPEND);
					echo $i . " Ошибка при добавлении примечания - " . $notes_result . "\n";
				}
				if ($notes_result_errors = $notes_result['_embedded']['errors']) {
					// При неудачном апдейте пишем лог ошибок данной итерации, пропускаем добавление примечаний
					$notes_add_errors_put_result_json = file_put_contents(APP_DIR."/errors/notes-add-errors.json", json_encode($notes_result_errors), FILE_APPEND);
				}
			}
			$copy_file = copy($files_dir.'/'.$update_file, APP_DIR.'/done-files/'.$update_file);
			if (!$copy_file) {
				echo "Ошибка при копировании файла\n";
			} else {
				echo $i . " Файл скопирован в исполненные\n";
				$unlink_file = unlink($files_dir.'/'.$update_file);
			}
		}
	} else {
		echo "Ошибка записи\n";
	}
} else {
	echo "Ошибка авторизации\n";
	return $result;
}
