<?php
namespace Cli\Helpers;
use \Libs\Http\Interfaces as Http;

if (!defined('BOOTSTRAP')) throw new \RuntimeException('Direct access denied');

class Api_Client extends \Helpers\Api_Client {

	/**
	 * Устанавливаем необходимые параметры для подключения
	 * @param array     $account - Account with $account['lang'] == 'ru' | 'en'
	 * @param Http\Curl $curl
	 */
	public function __construct(array $account, Http\Curl $curl)
	{
		$this->_curl = $curl;
		$this->_account = $account;
		$this->_subdomain = ($this->_account['lang'] === 'ru') ? 'customers' : 'customersus';
		$this->_protocol = 'https://';
		$this->_host = 'amocrm.';
		$this->_host .= 'ru';
		$this->_cookie_path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/tmp/cookie_api_client_' . $this->_subdomain . '.txt';
		$this->_query_authorization = TRUE;
	}
}
