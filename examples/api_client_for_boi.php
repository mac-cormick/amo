<?php
namespace Helpers;

if (!defined('BOOTSTRAP')) {
	throw new \RuntimeException('Direct access denied');
}

use Libs\Account\Interfaces\Account;
use Libs\Http\Interfaces\Curl;
use Libs\Http\Interfaces\Routes;

/**
 * Класс работы с API 2.0
 * @link https://developers.amocrm.ru/rest_api
 */
class Api_Client implements Interfaces\Api_Client
{
	protected
		/** @var Curl */
		$_curl,
		/** @var array */
		$_account,
		/** @var string */
		$_protocol,
		/** @var string */
		$_subdomain,
		/** @var string */
		$_host,
		/** @var string */
        $_login = CUSTOMERS_API_USER_LOGIN,
		/** @var string */
        $_api_hash = CUSTOMERS_API_USER_HASH,
		/** @var string */
		$_user_agent = 'amoCRM-API-client/2.0',
		/** @var bool */
		$_authorized = FALSE,
		/** @var string */
		$_cookie_path,
		/** @var bool Использовать ли куки */
		$_use_cookies = TRUE,
		/** @var bool Авторизация по GET-параметрам: USER_LOGIN & USER_HASH */
		$_query_authorization = FALSE,
		/** @var null|array */
		$_response_info,
		/** @var null|mixed */
		$_raw_response = NULL,
		/** @var array */
		$_last_request = [],
		/** @var null|mixed */
		$_response = NULL,
		$_timeout = 15,
		$_max_limit_rows = 500,
		$_methods =
		[
			'auth'                  => '/private/api/auth.php?type=json',
			'secret_auth'           => '/private/api/secret_auth.php',
			'account'               => '/private/api/v2/json/accounts/current',
			'company'               => '/private/api/v2/json/company/list',
			'company_set'           => '/private/api/v2/json/company/set',
			'contacts'              => '/private/api/v2/json/contacts/list',
			'contacts_set'          => '/private/api/v2/json/contacts/set',
			'contacts_links'        => '/private/api/v2/json/contacts/links',
			'leads'                 => '/private/api/v2/json/leads/list',
			'leads_set'             => '/private/api/v2/json/leads/set',
			'notes'                 => '/private/api/v2/json/notes/list',
			'notes_set'             => '/private/api/v2/json/notes/set',
			'tasks_set'             => '/private/api/v2/json/tasks/set',
			'pipelines'             => '/private/api/v2/json/pipelines/list',
			'notifications'         => '/private/api/v2/json/notifications/list',
			'notifications_set'     => '/private/api/v2/json/notifications/set',
			'customers'             => '/private/api/v2/json/customers/list',
			'customers_set'         => '/private/api/v2/json/customers/set',
			'customers_periods'     => '/private/api/v2/json/customers_periods/list',
			'customers_periods_set' => '/private/api/v2/json/customers_periods/set',
			'transactions'          => '/private/api/v2/json/transactions/list',
			'transactions_set'      => '/private/api/v2/json/transactions/set',
			'links'                 => '/private/api/v2/json/links/list',
			'links_set'             => '/private/api/v2/json/links/set',
			'inbox_delete'			=> '/private/api/v2/json/inbox/delete',
			'tasks'                 => '/private/api/v2/json/tasks/list',
		],

		$_promo_methods = [
			'accounts/id'      => '/api/accounts/id',
			'accounts/domains' => '/api/accounts/domains',
		],

		$_element_types =
		[
			AMO_LEADS_TYPE        => self::ELEMENT_LEADS,
			AMO_CONTACTS_TYPE     => self::ELEMENT_CONTACTS,
			AMO_COMPANIES_TYPE    => self::ELEMENT_COMPANIES,
			AMO_TASKS_TYPE        => self::ELEMENT_TASKS,
			AMO_NOTES_TYPE        => self::ELEMENT_NOTES,
			AMO_CUSTOMERS_TYPE    => self::ELEMENT_CUSTOMERS,
			AMO_TRANSACTIONS_TYPE => self::ELEMENT_TRANSACTIONS,
		],

		/** @deprecated уже не используется */
		$_contact_qualification_field_id = 1164894,

		/** @deprecated уже не используется */
		$_contact_qualification_true_value_id = 2666500,

		/** @deprecated уже не используется */
		$_company_qualification_true_value_id = 1149966,

		$_headers = [];

	/**
	 * Устанавливает необходимые параметры для подключения
	 * @param Account $account
	 * @param Curl $curl
	 * @param Routes $routs
	 */
	public function __construct(Account $account, Curl $curl, Routes $routs)
	{
		$this->_curl = $curl;
		$this->_account = $account->current();
		$this->_subdomain = ($this->_account['lang'] == 'ru') ? 'customers' : 'customersus';
		$this->_protocol = ($_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'https://';
		$this->_host = $routs->base_host();
		$this->_cookie_path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/tmp/cookie_api_client_' . $this->_subdomain . '.txt';
	}

	/**
	 * @param string $subdomain
	 * @param string $login
	 * @param string $api_key
	 * @return $this
	 */
	public function set_auth_data($subdomain, $login, $api_key) {
		$this->_subdomain = $subdomain;
		$this->_login = $login;
		$this->_api_hash = $api_key;

		return $this;
	}

	/**
	 * Использовать ли авторизацию через GET-параметры: USER_LOGIN & USER_HASH
	 * @param bool $bool
	 * @return $this
	 */
	public function use_query_authorization($bool) {
		$this->_query_authorization = (bool)$bool;

		return $this;
	}

	/**
	 * Использовать ли куки
	 * @param bool $bool
	 * @return $this
	 */
	public function use_cookies($bool) {
		$this->_use_cookies = (bool)$bool;

		return $this;
	}

	/**
	 * Добавление HTTP-заголовка для всех запросов
	 * @param string $header
	 * @param string $value
	 * @return $this
	 */
	public function add_header($header, $value)
	{
		$this->_headers[$header] = $header . ': ' . $value;

		return $this;
	}

	/**
	 * Отправка запроса
	 * @param string $url         URL
	 * @param mixed  $data        Данные для передачи
	 * @param bool   $json_encode Сделать json_encode($data) и передать заголовок Content-Type: application/json
	 * @return array|null
	 */
	protected function send_request($url, $data = NULL, $json_encode = FALSE)
	{
		$this->clear_request_info();

		if ($this->_query_authorization) {
			$auth_params = ['USER_LOGIN' => $this->_login, 'USER_HASH' => $this->_api_hash];
			$url .= ((strpos($url, '?') === FALSE) ? '?' : '&') . http_build_query($auth_params);
		}

		$this->_last_request = [
			'url'         => $url,
			'json_encode' => $json_encode,
		];

		$this->_curl->init($url);
		$this->_curl
			->option(CURLOPT_CONNECTTIMEOUT, $this->_timeout)
			->option(CURLOPT_TIMEOUT, $this->_timeout)
			->option(CURLOPT_USERAGENT, $this->_user_agent);

		if ($this->_use_cookies) {
			$this->_curl
				->option(CURLOPT_COOKIEFILE, $this->_cookie_path)
				->option(CURLOPT_COOKIEJAR, $this->_cookie_path);
		}

		if ($data) {
			$this->_curl
				->option(CURLOPT_CUSTOMREQUEST, 'POST')
				->option(CURLOPT_POST, TRUE)
				->option(CURLOPT_POSTFIELDS, $json_encode ? json_encode($data, JSON_UNESCAPED_UNICODE) : http_build_query($data));

			$this->_last_request['data'] = $data;
		}

		$headers = $this->_headers ?: [];

		if ($json_encode) {
			$headers['Content-Type'] = 'Content-Type: application/json; charset=utf-8';
		}

		if ($headers) {
			$this->_curl->option(CURLOPT_HTTPHEADER, $headers);
		}

		$this->_last_request['headers'] = $headers;

		$res = $this->_curl->exec();
		$this->_response_info = $res->info();
		$this->_raw_response = $res->result();
		$this->_response = json_decode($this->_raw_response, TRUE);

		return $this->_response;
	}

	/**
	 * @param bool $protocol
	 * @return string
	 */
	public function get_host($protocol = TRUE)
	{
		return ($protocol ? $this->_protocol : '') . $this->_subdomain . '.' . $this->_host;
	}

	/**
	 * @return array
	 */
	public function get_last_request() {
		return $this->_last_request;
	}

	/**
	 * @return null|mixed
	 */
	public function get_response() {
		return $this->_response;
	}

	/**
	 * @return null|mixed
	 */
	public function get_raw_response() {
		return $this->_raw_response;
	}

	/**
	 * @return array|null
	 */
	public function get_response_info() {
		return $this->_response_info;
	}

	/**
	 * @return int|null
	 */
	public function get_response_code() {
		return isset($this->_response_info['http_code']) ? (int)$this->_response_info['http_code'] : NULL;
	}

	/**
	 * @return array
	 */
	public function get_full_request_info()
	{
		return [
			'last_request'       => $this->get_last_request(),
			'last_response'      => $this->get_response(),
			'last_raw_response'  => $this->get_raw_response(),
			'last_response_info' => $this->get_response_info(),
		];
	}

	/**
	 * @return $this
	 */
	public function clear_request_info()
	{
		$this->_response = NULL;
		$this->_raw_response = NULL;
		$this->_response_info = NULL;
		$this->_last_request = [];

		return $this;
	}

	/**
	 * Get API element type name
	 * @param int $element_type
	 * @return string|null
	 */
	public function get_api_type($element_type) {
		return isset($this->_element_types[$element_type]) ? $this->_element_types[$element_type] : NULL;
	}

	/**
	 * @param string $element
	 * @return array|false
	 */
	protected function get_action_element_and_method($element)
	{
		$result = [];

		switch ($element) {
			case 'company':
			case 'companies':
				$result['element'] = self::ELEMENT_CONTACTS;
				$result['method'] = 'company_set';
				break;
			case 'contact':
			case 'contacts':
				$result['element'] = self::ELEMENT_CONTACTS;
				$result['method'] = 'contacts_set';
				break;
			case 'lead':
			case 'leads':
				$result['element'] = self::ELEMENT_LEADS;
				$result['method'] = 'leads_set';
				break;
			case 'note':
			case 'notes':
				$result['element'] = self::ELEMENT_NOTES;
				$result['method'] = 'notes_set';
				break;
			case 'task':
			case 'tasks':
				$result['element'] = self::ELEMENT_TASKS;
				$result['method'] = 'tasks_set';
				break;
			case 'notification':
			case 'notifications':
				$result['element'] = self::ELEMENT_NOTIFICATIONS;
				$result['method'] = 'notifications_set';
				break;
			case 'customers':
				$result['element'] = self::ELEMENT_CUSTOMERS;
				$result['method'] = 'customers_set';
				break;
			case 'customers_periods':
				$result['element'] = self::ELEMENT_CUSTOMERS_PERIODS;
				$result['method'] = 'customers_periods_set';
				break;
			case 'transactions':
				$result['element'] = self::ELEMENT_TRANSACTIONS;
				$result['method'] = 'transactions_set';
				break;
			case 'links':
				$result['element'] = self::ELEMENT_LINKS;
				$result['method'] = 'links_set';
				break;
			case 'inbox_delete':
				$result['element'] = self::ELEMENT_INBOX;
				$result['method'] = 'inbox_delete';
				break;
		}

		return $result ?: FALSE;
	}

	/**
	 * Запрос на авторизацию
	 * @return bool удалось ли автозироваться
	 */
	public function auth()
	{
		if ($this->_authorized) {
			return TRUE;
		}
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['auth'];
		var_dump($url);
		$data = [
			'USER_LOGIN' => $this->_login,
			'USER_HASH'  => $this->_api_hash,
		];
		$response = $this->send_request($url, $data, TRUE);
		if (!$response || empty($response['response']['auth'])) {
			return FALSE;
		}
		return $this->_authorized = TRUE;
	}

	/**
	 * @return bool
	 */
	public function is_authorized()
	{
		return (bool)$this->_authorized;
	}

	/**
	 * Запрос информации об аккаунте
	 * @return array|bool массив с данными или FALSE в случае ошибки
	 */
	public function get_account()
	{
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['account'];
		$response = $this->send_request($url);
		if (!$response || !empty($response['response']['error'])) {
			return FALSE;
		} else {
			return $response['response'];
		}
	}

	/**
	 * Поиск по сущности
	 * @param string       $element - contacts | companies (company) | leads | pipelines | notifications
	 * @param string|array $search  - параметры запроса.
	 * Поиск происходит по таким полям, как: почта, телефон и любым иным полям.
	 * Не осуществляется поиск по заметкам и задачам.
	 * @return array|false массив сущностей или FALSE в случае ошибки
	 */
	public function find($element, $search)
	{
		$element = ($element === 'companies') ? 'company' : $element;

		if (!isset($this->_methods[$element])) {
			return FALSE;
		}

		if (is_array($search)) {
				// поиск по параметру ['id' => 123] или ['id' => [123, 456, 789]]
			$query = http_build_query($search);
		} else {
			// Поиск по строке
			$query = http_build_query(['query' => $search]);
		}

		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods[$element] . '?' . $query;
		$response = $this->send_request($url);

		if ($element === 'contacts_links') {
			$element = 'links';
		}

		$element = ($element === 'company') ? 'contacts' : $element; // костыль для нашего API, которое компании возвращает как контакты
		if (!$response || empty($response['response'][$element])) {
			return FALSE;
		}

		return $response['response'][$element];
	}

	/**
	 * Получение списка сделок, связанных указанными контактами.
	 * @param array $contacts_ids - id контактов
	 * @param array $params - additional query params
	 * @return array|bool
	 */
	public function get_contacts_links($contacts_ids, $params = [])
	{
		return $this->get_entities_links('contacts_link', $contacts_ids, $params);
	}

	/**
	 * Получение списка контактов, связанных указанными сделками.
	 * @param array $leads_ids - id сделок
	 * @param array $params - дополнительные GET-параметры
	 * @return array|bool
	 */
	public function get_leads_links($leads_ids, $params = [])
	{
		return $this->get_entities_links('deals_link', $leads_ids, $params);
	}

	/**
	 * Получение связей между контактами и сделками.
	 * @param string $links_type - contacts_link / deals_link
	 * @param array $ids - id сущностей
	 * @param array $params - дополнительные GET-параметры
	 * @return array|bool
	 */
	protected function get_entities_links($links_type, $ids, $params = [])
	{
		if (!$ids || !is_array($ids) || !($ids = array_unique(array_map('intval', $ids)))) {
			return FALSE;
		}
		if (!in_array($links_type, ['contacts_link', 'deals_link'], TRUE)) {
			return FALSE;
		}

		$params = is_array($params) ? $params : [];
		$query = array_merge($params, [$links_type => $ids]);
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['contacts_links'] . '?' . http_build_query($query);
		$response = $this->send_request($url);

		$result = [];
		if (isset($response['response']['links']) && is_array($response['response']['links'])) {
			$result = $response['response']['links'];
		}

		return $result;
	}

	/**
	 * Поиск сущности по её названию
	 * @param string $type - 'leads' | 'contacts' | 'companies'
	 * @param string $name - название сущности
	 * @param bool   $strict - использовать точное сравнение
	 * @return array|bool - Найденная сущность или FALSE
	 */
	public function find_entity_by_name($type, $name, $strict = FALSE)
	{
		$entities = $this->find($type, $name);

		if ($entities) {
			foreach ($entities as $item) {
				if ($strict) {
					if ($item['name'] === $name) {
						return $item;
					}
				} else {
					if (strpos($item['name'], $name) !== FALSE) {
						return $item;
					}
				}
			}
		}

		return FALSE;
	}


	/**
	 * Поиск сделки по id аккаунта
	 * @param int $account_id
	 * @return array|bool - Найденная сущность или FALSE
	 */
	public function find_lead_by_account_id($account_id)
	{
		$lead_name = ($this->_account['lang'] == 'ru' ? 'Новый аккаунт: ' : 'New Account: ') . $account_id;
		return $this->find_entity_by_name('leads', $lead_name, TRUE);
	}


	/**
	 * Поиск компании аккаунта
	 * @param int    $account_id - id аккаунта
	 * @param string $subdomain  - субдомен аккаунта
	 * @return array|bool - Найденная сущность или FALSE
	 */
	public function find_account_company($account_id, $subdomain)
	{
		return $this->find_entity_by_name('company', $account_id . '|' . $subdomain, TRUE);
	}


	/**
	 * Обновление сущности
	 * @param $element - company | contacts | leads
	 * @param $data - массив массивов сущностей одного типа
	 * @param array $post_data - дополнительны данные для POST-запроса
	 * @return bool результат
	 */
	public function update($element, $data, $post_data = [])
	{
		return $this->action('update', $element, $data, $post_data);
	}

	/**
	 * Обновление сущностей, даже если их более 500
	 * @param string $element - company | contacts | leads
	 * @param array  $data    - массив массивов сущностей одного типа
	 * @return int кол-во обновленных сущностей
	 */
	public function update_all($element, $data)
	{
		$updated_count = 0;
		$data_chunks = array_chunk($data, $this->_max_limit_rows);
		foreach ($data_chunks as $chunk_index => $chunk) {
			$this->update($element, $chunk);
			$updated_count += count($chunk);
		}
		return $updated_count;
	}

	/**
	 * Добавление сущностей
	 * @param string $element    - company | contacts | leads
	 * @param array $action_data - массив массивов сущностей одного типа
	 * @param array $post_data   - дополнительны данные для POST-запроса
	 * @return bool результат
	 */
	public function add($element, $action_data, $post_data = [])
	{
		return $this->action('add', $element, $action_data, $post_data);
	}

	/**
	 * POST-запрос на действие
	 * @param string $action
	 * @param string $element
	 * @param array $action_data
	 * @param array $post_data - дополнительны данные для POST-запроса
	 * @return array|false
	 */
	public function action($action, $element, $action_data, array $post_data = []) {
		$result = FALSE;

		if ($params = $this->get_action_element_and_method($element)) {
			$data = [
				'request' => [
					$params['element'] => [
						$action => $action_data,
					],
				],
			];
			$data = array_merge($data, $post_data);
			$response = $this->post_request($element, $data, TRUE);

			$result = $this->return_action_response($response, $params['element'], $action);
		}

		return $result;
	}

	/**
	 * @param string $element
	 * @param array $post_data
	 * @param bool $json_encode
	 * @return array|bool
	 */
	public function post_request($element, $post_data, $json_encode = FALSE)
	{
		$result = FALSE;

		if ($params = $this->get_action_element_and_method($element)) {
			$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods[$params['method']];
			$result = $this->send_request($url, $post_data, $json_encode);
		}

		return $result;
	}

	/**
	 * В этот метод вынесена старая логика возврата результата метода "action".
	 * Данный метод можно переопределить в дочерних классах для реализации другой логики.
	 * @param array $response
	 * @param string $entity
	 * @param string|null $action
	 * @return bool
	 */
	protected function return_action_response($response, $entity, /** @noinspection PhpUnusedParameterInspection */ $action = NULL)
	{
		if (isset($response['response']) && empty($response['response'][$entity])) {
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Обновляет квалификацию контакта
	 * @deprecated Метод уже не используется
	 * @param int $contact_id id контакта
	 * @return bool
	 */
	public function update_contact_qualification($contact_id)
	{
		$data = [
			'id'            => $contact_id,
			'last_modified' => time() + 1,
			'custom_fields' => [
				[
					'id'     => $this->_contact_qualification_field_id,
					'values' => [
						[
							'value' => $this->_contact_qualification_true_value_id
						]
					]
				]

			]
		];
		return $this->update('contact', [$data]);
	}


	/**
	 * Добавляет примечание к элементку
	 * по умолчанию добавляет к контакту
	 * по умолчанию добавляет простую заметку
	 * @param int    $element_id
	 * @param string $text
	 * @param int    $note_type
	 * @param int    $element_type
	 * @return bool удалось ли добавить причечание
	 */
	public function add_note($element_id, $text, $note_type = 4, $element_type = 1)
	{
		$data['request']['notes']['add'] = [
			[
				'element_id'   => $element_id,
				'text'         => $text,
				'element_type' => $element_type,
				'note_type'    => $note_type,
			]
		];
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['notes_set'];
		$response = $this->send_request($url, $data, TRUE);
		if (!isset($response['response']['notes']) || !is_array($response['response']['notes'])) {
			return FALSE;
		}
		return TRUE;
	}


	/**
	 * Медод смотрит, есть ли в контактах уже такой номер (сверяет на последние 6 символов)
	 * @param string $phone_number номер телефона
	 * @param int    $contact_id   id контакта
	 * @return bool
	 */
	public function phone_exists($phone_number, $contact_id)
	{
		// фильтруем номер и вырезаем последние 6 символов
		$digit_pattern = '/[\D]/';
		$phone_number = preg_replace($digit_pattern, '', $phone_number);
		$phone_number = substr($phone_number, -6);
		if (strlen($phone_number) < 6) {
			return FALSE;
		}
		if (!$contacts = $this->find('contacts', $phone_number)) {
			return FALSE;
		}
		// Ищем похожие номера по последним 6-ти символам
		foreach ($contacts as $contact) {
			// смотрим номера во всех контактах, кроме добавленного
			if ($contact['id'] != $contact_id) {
				foreach ($contact['custom_fields'] as $field) {
					if ($field['code'] === 'PHONE') {
						foreach ($field['values'] as $value) {
							$phone = preg_replace($digit_pattern, '', $value['value']);
							if (preg_match('|' . $phone_number . '$|', $phone)) {
								return TRUE;
							}
						}
					}
				}
			}
		}
		return FALSE;
	}

	/**
	 * Получение значения кастомного поля по его коду или ID.
	 * @param array  $entity - сущность
	 * @param string $code - код кастомного поля
	 * @param int $id - id кастомного поля
	 * @return string|null
	 */
	function get_cf_values($entity, $code = NULL, $id = NULL)
	{
		$values = NULL;
		if (!empty($entity['custom_fields'])) {
			foreach ($entity['custom_fields'] as $cf) {
				if (!empty($code) && $cf['code'] == $code) {
					$values = $cf['values'][0];
					break;
				}
				if (!empty($id) && $cf['id'] == $id) {
					$values = $cf['values'][0];
					break;
				}
			}
		}

		return $values;
	}

	/**
	 * Проверяет наличие тега в массиве
	 * @param array  $tags - массив тегов
	 * @param string $name - название тега
	 * @return bool
	 */
	public function tag_exists($tags, $name)
	{
		foreach ($tags as $tag) {
			if ($tag['name'] === $name) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Создание строки тегов для обновления из массива тегов сущности
	 * @param array  $tags - массив тегов сущности
	 * @param string $new_tag - новый тег
	 * @return string
	 */
	public function tags_to_string($tags, $new_tag = NULL)
	{
		$result = [];
		foreach ($tags as $tag) {
			$result[] = $tag['name'];
		}
		if ($new_tag && !in_array($new_tag, $tags, TRUE)) {
			$result[] = $new_tag;
		}
		return implode(', ', $result);
	}

	protected function set_cookie_path($cookie_path)
	{
		if ($this->_cookie_path && (
				($this->_cookie_path !== $cookie_path) ||
				(file_exists($this->_cookie_path) && (time() - filemtime($this->_cookie_path)) > 30))) {
			$this->clear_auth();
		}

		$this->_cookie_path = $cookie_path;
	}

	/**
	 * Clear authorization
	 * @return $this
	 */
	public function clear_auth() {
		$this->_authorized = FALSE;
		if ($this->_cookie_path && is_file($this->_cookie_path)) {
			unlink($this->_cookie_path);
			$this->_cookie_path = NULL;
		}

		return $this;
	}

	public function __destruct() {
		$this->clear_auth();
	}
}
