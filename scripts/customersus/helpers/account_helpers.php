<?php

namespace Cli\Scripts\Single\Customersus\Helpers;

if (!defined('BOOTSTRAP')) {
	throw new \RuntimeException('Direct access denied');
}

use Libs\Db\Interfaces\Cluster;
use Cli\Helpers\Interfaces\Logger;
use Cli\Helpers\Api_Client;
use Helpers\API\Account\API_Helpers;

class Account_Helpers
{
	private $_db;
	private $_logger;
	private $_api;
	private $_dir;

	private $_temporary_files = [];
	private $_request_errors_file_name;
	private $_request_errors_data_file_name;

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
		$this->_dir = $dir;
		$this->_db = $db;

		if (!empty($this->_dir)) {
			$this->_temporary_files[] = $this->_request_errors_file_name = 'cus_request_errors_file.txt';
			$this->_temporary_files[] = $this->_request_errors_data_file_name = 'cus_request_errors_data_file.txt';
			foreach ($this->_temporary_files as $file_name) {
				unlink($this->_dir . '/' . $file_name);
			}
		}
	}

	/**
	 * Получение массива соответствий вида id-сущности => значение кастомного поля (для полей с одним значением(Текст, число, ...))
	 * @param array $entities        Массив сущностей, полученных по апи
	 * @param int   $field_id        ID кастомного поля
	 * @return array
	 */
	public function make_field_associations(array $entities, $field_id)
	{
		$result = $field_empty = [];

		foreach ($entities as $entity) {
			$filled = FALSE;
			foreach ($entity['custom_fields'] as $field) {
				if ($field['id'] == $field_id) {
					if (!empty($field['values'][0]['value'])) {
						$result[$entity['id']] = (int)$field['values'][0]['value'];
						$filled = TRUE;
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
		$path = $this->_dir . '/' . $file;
		file_put_contents($path, $data . "\n", FILE_APPEND);
	}

	/**
	 * Получение данных из файла построчно
	 * @param resource $handle    указатель на файл
	 * @param int      $count     количество получаемых строк
	 * @param bool     $decode
	 * @return null|array
	 */
	public function get_file_content($handle, $count, $decode = TRUE)
	{
		if (feof($handle)) {
			fclose($handle);
			return NULL;
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
	 * @throws \Exception
	 */
	public function process_post_request($entity, $method, array $data)
	{
		if ($method !== 'add' && $method !== 'update') {
			throw new \Exception('Unsupported type: ' . $method);
		}
		$result = $this->_api->$method($entity, $data);
		$resp_code = $this->_api->get_response_code();
		$response_info = $this->_api->get_response_info();

		if ($resp_code !== 200 && $resp_code !== 100) {
			$this->write_to_file($this->_request_errors_file_name, $response_info);
			$this->write_to_file($this->_request_errors_data_file_name, $data);
			$this->_logger->error('request error. Code ' . $resp_code);
		} elseif (count($result['_embedded']['errors'])) {
			$this->_logger->error('request errors found and logged');
			$this->write_to_file($this->_request_errors_file_name, $result['_embedded']['errors']);
			$this->write_to_file($this->_request_errors_data_file_name, $data);
		} else {
			$this->_logger->log('Updated successfully!');
		}
	}

	/**
	 * Получение данных по аккаунтам из таблицы b_iblock_element_prop_s4
	 * @param array $account_ids    Массив id аккаунтов
	 * @param array $fields         Получаемые столбцы (['IBLOCK_ELEMENT_ID', 'PROPERTY_79', ...])
	 * @return null|array
	 */
	public function get_accounts_info(array $account_ids, array $fields)
	{
		$db_result = [];

		if (!empty($account_ids) && !empty($fields)) {
			$account_ids = array_map('intval', $account_ids);
			$in = '(' . implode(',', $account_ids) . ')';
			$select = 'SELECT ' . implode(', ', $fields);

			$sql = $select . " FROM b_iblock_element_prop_s4 WHERE IBLOCK_ELEMENT_ID IN " . $in;

			$resource = $this->_db->query($sql);

			while ($row = $resource->fetch(FALSE)) {
				$db_result[] = $row;
			}
		}

		if (empty($db_result)) {
			$this->_logger->log('0 rows got');
			return NULL;
		}
		return $db_result;
	}

	/**
	 * Перемещение сделок в другой этап
	 * @param array $leads_ids    Массив id сделок
	 * @param int   $status_id    id статуса
	 * @param int   $pipeline_id  id ворнки
	 * @return void
	 */
	public function move_to_status(array $leads_ids, $status_id, $pipeline_id = NULL)
	{
		$leads_for_update = $this->_api->find('leads', ['id' => $leads_ids]);

		if (count($leads_for_update)) {
			$this->_logger->log('Updating ' . count($leads_for_update) . ' leads...');
			$update_data = [];
			foreach ($leads_for_update as $lead) {
				$update_data[] = [
					'id' => $lead['id'],
					'last_modified' => API_Helpers::update_last_modified($lead['last_modified']),
					'status_id' => $status_id,
					'pipeline_id' => $pipeline_id ?: NULL
				];
			}

			$this->process_post_request('leads', 'update', $update_data);
		}
	}
}
