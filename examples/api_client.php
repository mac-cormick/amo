<?php

/**
 * Класс работы с API 2.0
 * @link https://developers.amocrm.ru/rest_api
 * @property-read string $_subdomain
 * @property-read array $_last_response
 */
class Api_Client
{
	const COOKIES_DIR = '/var/www/account/common_files/tmp/cookies/';
	const TEMP_DIR = '/tmp/';

	protected
		$_curl = NULL,
		$_account = NULL,
		$_protocol = NULL,
		$_subdomain = NULL,
		$_host = NULL,
        $_user = 'amolyakov@team.amocrm.com',
        $_hash = '5af407911f9484927d1e44ac5eb7c7b7',
		$_user_agent = 'amoCRM-API-client/2.0',
		$_base_url,
		$_authorized = FALSE,
		$_cookie_path,
		$_max_limit_rows = 500,
		$_timeout = 17,
		$_request_attempt = 0,
		$_max_request_attempts = 5,
		$_errors = [],
		$_last_request = [],
		$_last_response = ['response' => NULL, 'info' => ['http_code' => NULL]],
		$_methods =
		[
			'auth'           => '/private/api/auth.php?type=json',
			'account'        => '/private/api/v2/json/accounts/current',
			'company'        => '/private/api/v2/json/company/list',
			'company_set'    => '/private/api/v2/json/company/set',
			'contacts'       => '/private/api/v2/json/contacts/list',
			'contacts_set'   => '/private/api/v2/json/contacts/set',
			'contacts_links' => '/private/api/v2/json/contacts/links',
			'leads'          => '/private/api/v2/json/leads/list',
			'leads_set'      => '/private/api/v2/json/leads/set',
			'notes'          => '/private/api/v2/json/notes/list',
			'notes_set'      => '/private/api/v2/json/notes/set',
			'tasks'          => '/private/api/v2/json/tasks/list',
			'tasks_set'      => '/private/api/v2/json/tasks/set',
			'pipelines_set'  => '/private/api/v2/json/pipelines/set',
			'pipelines'      => '/private/api/v2/json/pipelines/list',
			'fields_set'     => '/private/api/v2/json/fields/set',
		],

		$_contact_qualification_field_id = 1164894,
		$_contact_qualification_true_value_id = 2666500;

	protected static $instances = [];


	/**
	 * Устанавливаем необходимые параметры для подключения
	 * @param string $subdomain - 'customers' | 'customersus'
	 * @param string $protocol  - https | http
	 * @param string $host      - amocrm.ru | amocrm.com
	 * @param string $user      - user@mail.ru
	 * @param string $hash      - API HASH
	 * @param string $lang      - ru | en
	 */
	public function __construct($subdomain, $protocol = NULL, $host = NULL, $user = NULL, $hash = NULL, $lang = NULL)
	{
		$this->_subdomain = $subdomain . '.amolyakov';
		$this->_protocol = 'http://';
		$this->_host = $host ?: (($this->_subdomain == 'customersus') ? 'amocrm.com' : 'amocrm2.saas');
		$this->_user = $user ?: $this->_user;
		$this->_hash = $hash ?: $this->_hash;
		$lang = $lang ?: (($this->_host == 'amocrm2.saas') ? 'ru' : 'en');
		$this->_account = ['lang' => $lang];
		$this->_base_url = $this->_protocol . $this->_subdomain . '.' . $this->_host;
		$this->_cookie_path = $this->set_cookie_path();
		self::$instances[$subdomain] = $this;
	}


	/**
	 * Получение экземпляра класса для субдомена, если он есть. Иначе - FALSE.
	 * @param string $subdomain
	 * @return self|bool
	 */
	public static function get_instance($subdomain)
	{
		if (isset(self::$instances[$subdomain])) {
			return self::$instances[$subdomain];
		}
		return FALSE;
	}


	/**
	 * Получение свойства объекта
	 * @param string $property
	 * @return mixed|bool
	 */
	public function __get($property)
	{
		if (!property_exists($this, $property)) {
			return FALSE;
		}
		return $this->$property;
	}


	/**
	 * Отправка запроса
	 * @param string $url         URL
	 * @param mixed  $data        Данные для передачи
	 * @param bool   $json_encode Сделать json_encode($data) и передать заголовок Content-Type: application/json
	 * @param array  $headers     Массив HTTP-заголовков в виде строк
	 * @return string|bool
	 */
	protected function send_request($url, $data = NULL, $json_encode = FALSE, $headers = NULL)
	{
		if (++$this->_request_attempt > $this->_max_request_attempts) {
			return $this->stop_request();
		}

		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->_timeout);
		curl_setopt($curl, CURLOPT_TIMEOUT, $this->_timeout);
		curl_setopt($curl, CURLOPT_USERAGENT, $this->_user_agent);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->_cookie_path);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $this->_cookie_path);

		if ($data) {
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json_encode ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data);
		}

		if ($json_encode) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}

		if (is_array($headers)) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}

		$this->_last_request = ['url' => $url, 'data' => $data, 'json_encode' => $json_encode, 'headers' => $headers];
		$response = curl_exec($curl);
		$previous_code = $this->_last_response['info']['http_code'];
		$this->_last_response = ['response' => $response, 'info' => curl_getinfo($curl)];
		curl_close($curl);

		// Если пришел ответ "401 Unauthorized" - авторизуемся снова и повторим запрос
		if ($this->_last_response['info']['http_code'] == 401 && $previous_code != 401) {
			$this->_authorized = FALSE;
			if (!$this->auth()) {
				return $this->stop_request();
			}
			return call_user_func_array([$this, __FUNCTION__], func_get_args());
		}

		// Если пришел ответ "429 Too Many Requests", то подождем 1 - 2 секунды и повторим запрос
		if ($this->_last_response['info']['http_code'] == 429) {
			usleep(mt_rand(1000000, 2000000));
			return call_user_func_array([$this, __FUNCTION__], func_get_args());
		}

		$this->_request_attempt = 0;
		$response = json_decode($response, TRUE);
		return $response;
	}


	/**
	 * Используется для остановки повторения запросов.
	 * @return bool
	 */
	protected function stop_request() {
		$this->_request_attempt = 0;
		return FALSE;
	}


	/**
	 * Запрос на авторизацию
	 * @return bool - удалось ли автозироваться
	 */
	public function auth()
	{
		if ($this->_authorized) {
			return TRUE;
		}
		$url = $this->_base_url . $this->_methods['auth'];
		file_put_contents("/var/www/sata/amolyakov/account/2.0/logs.html", "AUTH URL - <br><pre>".var_export($url,TRUE)."</pre><br><hr>", FILE_APPEND);
		$data = [
			'USER_LOGIN' => $this->_user,
			'USER_HASH'  => $this->_hash,
		];
		file_put_contents("/var/www/sata/amolyakov/account/2.0/logs.html", "AUTH DATA - <br><pre>".var_export($data,TRUE)."</pre><br><hr>", FILE_APPEND);
		$response = $this->send_request($url, $data, TRUE);
		file_put_contents("/var/www/sata/amolyakov/account/2.0/logs.html", "AUTH RESPONSE - <br><pre>".var_export($response,TRUE)."</pre><br><hr>", FILE_APPEND);
		if (empty($response['response']['auth']) || $response['response']['auth'] !== TRUE) {
			$this->_errors[] = [__FUNCTION__ => $response];
			return FALSE;
		}
		return $this->_authorized = TRUE;
	}


	/**
	 * Получение информации об аккаунте.
	 * @return array|bool
	 */
	public function current_account()
	{
		$response = $this->send_request($this->_base_url . $this->_methods['account']);
		if (empty($response['response']['account'])) {
			return FALSE;
		}
		return $response['response']['account'];
	}

	/**
	 * Получение всех сделок.
	 * @return array|bool
	 */
	public function all_leads()
	{
		$response = $this->send_request($this->_base_url . $this->_methods['leads']);
		if (empty($response['response']['leads'])) {
			return FALSE;
		}
		return $response['response']['leads'];
	}


	/**
	 * Поиск по сущности
	 * @param string       $element       - 'contacts'|'company'|'leads'|'notes'
	 * @param string|array $search        - искомые данные.
	 *                                    Поиск происходит по таким полям, как : почта, телефон и любым иным полям.
	 *                                    Не осуществляется поиск по заметкам и задачам.
	 * @param int          $modified_date - Дата изменения - UNIX timestamp
	 * @param bool		   $only_text_search- Флаг, показывающий, следует ли производить поиск только по тексту
	 * @return array|bool массив сущностой или FALSE в случае ошибки
	 */
	public function get_entities($element, $search, $modified_date = NULL, $only_text_search = false)
	{
		$element = ($element === 'companies') ? 'company' : $element;
		$allowed_elements = ['contacts', 'company', 'leads', 'notes', 'tasks', 'pipelines'];

		if (!in_array($element, $allowed_elements, TRUE)) {
			return FALSE;
		}

		if (is_array($search)) {
			if (!$only_text_search) {
				// поиск по параметру ['id' => 123] или ['id' => [123, 456, 789]]
				$query = http_build_query($search);
			}
			else
			{
				// поиск по тексту ['антон', 'вася', 'петя']
				$query = http_build_query(['query' => $search]);
			}
		} else {
			// Поиск по строке
			$query = http_build_query(['query' => $search]);
		}

		$modified_header = NULL;
		if ($modified_date) {
			$modified_header = ['If-Modified-Since: ' . date('D, d M Y H:i:s', $modified_date)];
		}

		$url = $this->_base_url . $this->_methods[$element] . '?' . $query;

		$response = $this->send_request($url, NULL, NULL, $modified_header);

		if ($element === 'company') {
			$element = 'contacts'; // костыль для нашего API, которое компании возвращает как контакты
		}

		if (!$response || empty($response['response'][$element])) {
			return FALSE;
		}
		return $response['response'][$element];
	}


	/**
	 * Получение сущностей по id, даже если их более 500
	 * @param string $element - contacts | companies (company) | leads | notes
	 * @param array  $ids
	 * @return array|bool
	 */
	public function get_all_entities_by_ids($element, $ids)
	{
		$result = [];
		$ids_chunks = array_chunk($ids, 250); // 250 - чтобы не получить ошибку 414 Request-URI Too Large

		foreach ($ids_chunks as $chunk_index => $ids_chunk) {
			if (!$response = $this->get_entities($element, ['id' => $ids_chunk])) {
				break;
			}
			if (defined('DEBUG') && DEBUG === TRUE) {
				echo "\n" . ($chunk_index + 1) . " chunk fetched: " . count($response) . "\n\n";
			}
			$result = array_merge($result, $response);
		}

		if (empty($result)) {
			return FALSE;
		}
		return $result;
	}


	/**
	 * Получает через API все искомые сущности, измененные с определенной даты, даже если их более 500
	 * @param string       $element       - contacts | companies (company) | leads
	 * @param string|array $search        - искомые данные.
	 * @param int|null     $modified_date - дата изменения - UNIX timestamp
	 * @param int          $to_date       - по какую дату
	 * @param callable     $callback      - callback функция, которой будет передаваться каждый чанк полученных сущностей. Если ф-ция вернет TRUE, данные добавятся в результирующий массив.
	 * @return array|bool массив сущностой или FALSE в случае ошибки
	 */
	public function get_all_entities_since_date($element, $search, $modified_date, $to_date = NULL, \Closure $callback = NULL)
	{
		$lap = 1;
		$search['limit_offset'] = 0;
		$search['limit_rows'] = $this->_max_limit_rows;
		$result = [];
		$stop = FALSE;

		while (!$stop && ($response = $this->get_entities($element, $search, $modified_date))) {
			if (isset($to_date)) {
				foreach ($response as $index => $entity) {
					if (isset($entity['last_modified']) && intval($entity['last_modified']) > $to_date) {
						$stop = TRUE;
						unset($response[$index]);
					}
				}
			}

			$search['limit_offset'] += $this->_max_limit_rows;
			if (defined('DEBUG') && DEBUG === TRUE) {
				echo "\n" . $lap . ' ' . $element . " chunk fetched: " . $search['limit_offset'] . ' - ' . $search['limit_rows'] . "\tUsed memory: " . memory_get_usage(TRUE) . "\tPeak: " . memory_get_peak_usage(TRUE) . "\n";
			}
			$lap++;

			if ($callback && !$callback($response)) {
				// Если $callback-функция вернула FALSE, то в результат ничего добавлять не будем
				$response = [];
			}

			$result = array_merge($result, $response);
		}

		if (empty($result)) {
			$result = FALSE;
		}

		return $result;
	}


	/**
	 * Получение связей "сделки - контакты" или "контакты - сделки"
	 * @param string $entity - deals_link | contacts_link
	 * @param array  $params - массив параметров: [id = [], since = timestamp, limit_rows = 500, limit_offset = 0]
	 * @return array|bool
	 */
	public function get_links($entity, $params)
	{
		if ($entity === 'deals_link' || $entity === 'leads_link' || $entity === 'leads') {
			$entity = 'deals_link';
		} elseif ($entity === 'contacts_link' || $entity === 'contacts') {
			$entity = 'contacts_link';
		} else {
			throw new \InvalidArgumentException('Invalid entity');
		}

		$query = [];
		$modified_header = NULL;

		if (isset($params['id'])) {
			// Массив id контактов или сделок.
			// Используется для получения id сделок, связанных с переданным списком id контактов (и наоборот)
			$query[$entity] = $params['id'];
		}
		if (isset($params['since'])) {
			$modified_header = ['If-Modified-Since: ' . date('D, d M Y H:i:s', $params['since'])];
		}
		if (isset($params['limit_offset'])) {
			$query['limit_offset'] = $params['limit_offset'];
			$query['limit_rows'] = (isset($params['limit_rows']) ? $params['limit_rows'] : $this->_max_limit_rows);
		}

		$url = $this->_base_url . $this->_methods['contacts_links'] . '?' . http_build_query($query);
		$response = $this->send_request($url, NULL, NULL, $modified_header);

		if (!$response || empty($response['response']['links'])) {
			return FALSE;
		}
		return $response['response']['links'];
	}


	/**
	 * Получение массива всех связей с id переданных сущностей
	 * @param $entity - leads|contacts
	 * @param $ids    - id сущностей
	 * @return array|null
	 */
	public function get_all_links($entity, $ids)
	{
		// Делим массив на чанки по 250, чтобы не привысить макс. длину URL
		$chunks = array_chunk($ids, 250);
		$links = [];

		foreach ($chunks as $chunk_index => $chunk) {
			$offset = 0;
			do {
				if (defined('DEBUG') && DEBUG === TRUE) {
					echo "\nChunk: " . $chunk_index . " - Offset: " . $offset . " Count: " . count($chunk);
				}
				$query_params = [
					'id'           => $chunk,
					'limit_offset' => $offset,
					'limit_rows'   => $this->_max_limit_rows,
				];

				if (!$response = $this->get_links($entity, $query_params)) {
					break;
				}

				$links = array_merge($links, $response);
				$offset += $this->_max_limit_rows;
			} while (count($response) == $this->_max_limit_rows);
		}

		if (empty($links)) {
			return NULL;
		}

		return $links;
	}


	/**
	 * Получение связанных со сделкой сущностей с наименьшим id.
	 * @param int|array $lead - id сделки или сама сделка в виде массива
	 * @param bool      $get_contact
	 * @param bool      $get_company
	 * @return array
	 */
	public function get_first_linked_entities($lead, $get_contact = TRUE, $get_company = TRUE)
	{
		$result = [
			'lead'    => FALSE,
			'contact' => FALSE,
			'company' => FALSE
		];

		if (is_array($lead)) {
			$lead_id = $lead['id'];
			$result['lead'] = $lead;
		} else {
			// Если $lead - число
			$lead_id = $lead;
			$leads = $this->get_entities('leads', ['id' => $lead_id]);
			if (empty($leads)) {
				return $result;
			}
			$result['lead'] = $lead = $leads[0];
		}

		if ($get_contact) {
			// Связанный контакт
			$links = $this->get_all_links('leads', [$lead_id]);
			if (!empty($links[0]['contact_id'])) {
				$link = $this->get_first_by_field($links, 'contact_id');
				$contacts = $this->get_entities('contacts', ['id' => $link['contact_id']]);
				if (!empty($contacts)) {
					$result['contact'] = $contacts[0];
				}
			}
		}

		if ($get_company) {
			// Связанная компания
			if (!empty($lead['linked_company_id'])) {
				$companies = $this->get_entities('company', ['id' => $lead['linked_company_id']]);
				if (!empty($companies)) {
					$result['company'] = $companies[0];
				}
			}
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
		$entities = $this->get_entities($type, $name);

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
	 * Поиск сделки, в названии которой укзан переданный ID аккаунта
	 * @param int $account_id
	 * @return array|bool - Найденная сущность или FALSE
	 */
	public function find_lead_by_account_id($account_id)
	{
		$lead_name = ($this->_account['lang'] == 'ru' ? 'Новый аккаунт: ' : 'New Account: ') . $account_id;
		file_put_contents("/var/www/sata/amolyakov/account/2.0/logs.html", "LEAD NAME - <br><pre>".var_export($lead_name,TRUE)."</pre><br><hr>", FILE_APPEND);
		return $this->find_entity_by_name('leads', $lead_name, TRUE);
	}


	/**
	 * Поиск компании, связанной с аккаунтом
	 * @param int    $account_id - id аккаунта
	 * @param string $subdomain  - субдомен аккаунта
	 * @return array|bool - Найденная сущность или FALSE
	 */
	public function find_company_by_account($account_id, $subdomain)
	{
		return $this->find_entity_by_name('company', $account_id . '|' . $subdomain, TRUE);
	}


	/**
	 * Обновление компании
	 * @param string $element - company | contacts | leads
	 * @param array  $data    - массив массивов сущностей одного типа
	 * @return array $response
	 */
	public function update($element, $data)
	{
		$method = NULL;
		switch ($element) {
			case 'company':
			case 'companies':
				$element = 'contacts';
				$method = 'company_set';
				break;
			case 'contact':
			case 'contacts':
				$element = 'contacts';
				$method = 'contacts_set';
				break;
			case 'lead':
			case 'leads':
				$element = 'leads';
				$method = 'leads_set';
				break;
			default:
				return FALSE;
		}
		$url = $this->_base_url . $this->_methods[$method];
		$post_data = [];
		$post_data['request'][$element]['update'] = $data;
		$response = $this->send_request($url, $post_data, TRUE);
		if (defined('DEBUG') && DEBUG === TRUE) {
			echo var_export($response, TRUE);
		}
		return $response;
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
			if (defined('DEBUG') && DEBUG === TRUE) {
				echo "\n\n" . ($chunk_index + 1) . ' stage of update: ' . $chunk_index * $this->_max_limit_rows . ' - ' . (($chunk_index + 1) * $this->_max_limit_rows) . ". Updated: " . $updated_count . "\n\n";
			}
		}
		return $updated_count;
	}


	/**
	 * Обновляет квалификацию контакта
	 * @param int $contact_id - id контакта
	 * @return array $response
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
	 * Добавление сущностей
	 * @param string $type
	 * @param array  $entities
	 * @param bool $is_enum добавление enum
	 * @return bool
	 */
	public function add($type, array $entities, $is_enum = FALSE)
	{
		if (!in_array($type, [
			'contacts',
			'company',
			'companies',
			'leads',
			'notes',
			'tasks',
			'pipelines',
			'fields'
		], TRUE)) {
			return FALSE;
		}
		$type = ($type == 'companies' ? 'company' : $type);
		$url = $this->_base_url . $this->_methods[$type . '_set'];
		$type = ($type == 'company' ? 'contacts' : $type); // вот такие костыли для нашего API
		$data = [];
		$method = $is_enum ? 'add.enum' : 'add';
		$data['request'][$type][$method] = $entities;
		$response = $this->send_request($url, $data, TRUE);

		if (empty($response['response'][$type][$method])) {
			$this->_errors[] = [__FUNCTION__ => $response];
			return FALSE;
		}
		return $response['response'][$type][$method];
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
		$url = $this->_base_url . $this->_methods['notes_set'];
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
		if (!$contacts = $this->get_entities('contacts', $phone_number)) {
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
	 * Поиск сущности с наименьшим значением поля в массиве.
	 * Можно использовать для поиска сущности с наименьшим id.
	 * @param array  $entities
	 * @param string $field
	 * @return array
	 */
	public function get_first_by_field($entities, $field = 'id')
	{
		$first = $entities[0];
		$first_id = (int)$first[$field];
		foreach ($entities as $entity) {
			if (intval($entity[$field]) < $first_id) {
				$first = $entity;
			}
		}
		return $first;
	}


    /**
     * Получение значения кастомного поля по его коду.
     * @param string $code - EMAIL | PHONE | POSITION | IM
     * @param array  $entity - контакт
     * @return string|null
     */
    function get_cf_value_by_code($code = false, $entity, $id = false)
    {
        $value = NULL;
        if (!empty($entity['custom_fields'])) {
            foreach ($entity['custom_fields'] as $cf) {
                if ($code && $cf['code'] == $code && !empty($cf['values'][0]['value'])) {
                    $value = trim($cf['values'][0]['value']);
                    break;
                }
                if ($id && $cf['id'] == $id) {
                    $value = trim($cf['values'][0]['value']);
                    break;
                }
            }
        }

        return $value;
    }


	/**
	 * Проверяет наличие тега в массиве
	 * @param array  $tags
	 * @param string $name
	 * @return bool
	 */
	public function tag_exists($tags, $name)
	{
		foreach ($tags as $tag) {
			if ($tag['name'] == $name) {
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
		if ($new_tag) {
			$result[] = $new_tag;
		}
		return implode(', ', $result);
	}


	/**
	 * Получение массива возможных ошибок
	 * @return array
	 */
	public function get_errors()
	{
		$errors = [
			'errors'        => $this->_errors,
			'last_request'  => $this->_last_request,
			'last_response' => $this->_last_response,
		];
		return $errors;
	}


	public function __destruct()
	{
		if (is_file($this->_cookie_path)) {
			unlink($this->_cookie_path);
		}
	}


	/**
	 * Определяем, куда класть куку
	 * Если в директорию кук писать нельзя, то кладём в /tmp
	 * @return string
	 */
	protected function set_cookie_path() {
		$path = is_dir(self::COOKIES_DIR) && is_writable(self::COOKIES_DIR) ? self::COOKIES_DIR : self::TEMP_DIR;

		return $path . 'cookie_api_client_' . $this->_subdomain . '_' . mt_rand() . '.txt';
	}
}
