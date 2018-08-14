<?php

namespace Cli\Scripts\Single\Customersus\Helpers;

if (!defined('BOOTSTRAP')) {
	throw new \RuntimeException('Direct access denied');
}

use Libs\Db\Interfaces\Cluster;
use Cli\Helpers\Interfaces\Logger;
use Cli\Helpers\Api_Client;

define('REQUEST_ERRORS_FILE_NAME', 'cus_request_errors_file.txt');
define('REQUEST_ERRORS_DATA_FILE_NAME', 'cus_request_errors_data_file.txt');

class Account_Helpers
{
	private $_db;
	private $_logger;
	private $_api;
	private $_dir;

	/**
	 * @param Logger     $logger
	 * @param Api_Client $api
	 * @param Cluster    $db
	 * @param string     $dir    Путь к директории, в которую кладем временные файлы скрипта
	 */
	public function __construct(Logger $logger, Api_Client $api, Cluster $db = NULL, $dir = NULL)
	{
		$this->_logger = $logger;
		$this->_api = $api;
		$this->_dir = $dir . '/';
		$this->_db = $db;
	}

	/**
	 * Получение массива соответствий вида id-сущности => значение кастомного поля (для полей с одним значением(Текст, число, ...))
	 * @param array $entities        Массив сущностей, полученных по апи
	 * @param int   $field_id        ID кастомного поля
	 * @return array
	 */
	public function field_associations($entities, $field_id)
	{
		$result = $field_empty = [];

		foreach ($entities as $entity) {
			$filled = FALSE;
			foreach ($entity['custom_fields'] as $field) {
				if ($field['id'] == $field_id) {
					$filled = TRUE;
					$field_value = (int)$field['values'][0]['value'];
					if (!empty($field_value)) {
						$result[$entity['id']] = $field_value;
					}
					break;
				}
			}
			if (!$filled) {
				$field_empty[] = $entity['id'];
			}
		}

		return [
			'result' => $result,
			'empty_field_entities' => $field_empty
		];
	}

	/**
	 * Запись в файл
	 * @param string $file        Имя файла
	 * @param mixed  $data        Данные для записи
	 * @param bool  $encode
	 * @return void
	 */
	public function write_to_file($file, $data, $encode = TRUE)
	{
		if ($encode) {
			$data = json_encode($data);
		}
		$path = $this->_dir . $file;
		file_put_contents($path, $data . "\n", FILE_APPEND);
	}

	/**
	 * Получение данных из файла построчно
	 * @param resource $handle    указатель на файл
	 * @param int      $count     количество получаемых строк
	 * @param bool     $decode
	 * @return array|bool
	 */
	public function get_file_content($handle, $count, $decode = TRUE)
	{
		if (feof($handle)) {
			fclose($handle);
			return FALSE;
		}
		$result = [];

		for ($x = 0; $x < $count; $x++) {
			$next_str = fgets($handle);
			if ($decode) {
				$next_str = json_decode($next_str, TRUE);
			}
			if (!$next_str) {
				$this->_logger->log('End of file');
				break;
			}
			$result[] = $next_str;
		}
		return $result;
	}

	/**
	 * Совершение и обработка результата запроса добавлениея/изменения сущностей
	 * @param string $entity    company | contacts | leads
	 * @param string $method    update | add
	 * @param array  $data		массив массивов данных по обновляемым\добавляемым сущностям
	 * @return void
	 */
	public function process_post_request($entity, $method, $data)
	{
		$result = $this->_api->$method($entity, $data);
		$resp_code = $this->_api->get_response_code();
		$response_info = $this->_api->get_response_info();

		if ($resp_code !== 200 && $resp_code !== 100) {
			$this->write_to_file(REQUEST_ERRORS_FILE_NAME, $response_info);
			$this->write_to_file(REQUEST_ERRORS_DATA_FILE_NAME, $data);
			$this->_logger->error('request error. Code ' . $resp_code);
		} elseif (count($result['_embedded']['errors'])) {
			$this->_logger->error('request errors found and logged');
			$this->write_to_file(REQUEST_ERRORS_FILE_NAME, $result['_embedded']['errors']);
			$this->write_to_file(REQUEST_ERRORS_DATA_FILE_NAME, $data);
		} else {
			$this->_logger->log('Updated successfully!');
		}
	}

	/**
	 * Получение данных по аккаунтам из таблицы b_iblock_element_prop_s4
	 * @param array $accounts        Массив id аккаунтов
	 * @param array $columns         Получаемые столбцы (['IBLOCK_ELEMENT_ID', 'PROPERTY_79', ...])
	 * @return array|bool
	 */
	public function get_accounts_info($accounts, $columns)
	{
		$accounts_ids = array_map('intval', $accounts);
		$in = '(' . implode(',', $accounts_ids) . ')';
		$select = 'SELECT ' . implode(', ', $columns);

		$sql = $select . " FROM b_iblock_element_prop_s4 WHERE IBLOCK_ELEMENT_ID IN " . $in;

		$resource = $this->_db->query($sql);
		$db_result = [];

		$i = 0;
		while ($row = $resource->fetch(FALSE)) {
			foreach ($columns as $column) {
				$db_result[$i][$column] = $row[$column];
			}
			$i++;
		}

		if (empty($db_result)) {
			$this->_logger->log('0 rows got');
			return FALSE;
		}
		return $db_result;
	}
}
