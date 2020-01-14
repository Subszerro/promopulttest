<?php

/**
 * Задача:
 * Скрипт, который, используя протокол http, вытягивает все темы писем из аккаунта на mail.ru и выводит их в виде списка в консоли или на странице.
 * Для теста можно взять аккаунт batanik22@mail.ru, пароль: utofbmz4
 * 
 * План решения задачи:
 * 1. Авторизоваться на mail.ru
 * 2. Получить исходный код страницы https://e.mail.ru/messages/inbox/
 * 3. Данные для списка писем получаются через Ajax, в исходном коде страницы заголовков писем нет, нужно разобрать Ajax-запрос
 * 4. Запрос уходит на https://e.mail.ru/api/v1/threads/status/smart, в качестве основных параметров используется email и token + некоторые фильтры
 * 5. Из основных параметров мы не знаем только token, парсим его в теле страницы в JS-коде
 * 6. После отправки запроса на API, разбираем ответ, находим $response->body->threads (массив объектов), выводим в цикле свойство subject из каждого объекта
 */


/**
 * Класс для работы с почтовым ящиком Mail.ru
 *
 * @author Dmitry Evtushenko
 * @version 1.0, 12.01.2020
 */
class MailRu 
{
	/** @var string Email пользователя */
	private $email;
	/** @var string Пароль пользователя */
	private $password;
	/** @var string Логин */
	private $login;
	/** @var string Домен */
	private $domain;
	/** @var string Токен для получения данных из API */
	private $token;

	/** @var string Ответ от сервера */
	private $response;
	/** @var string Адрес страницы с сообщениями */
	private $inboxUrl;

	/** @var string URL для авторизации */
	const AUTH_URL = 'https://auth.mail.ru/cgi-bin/auth';
	/** @var string URL для получение данных с содержимым почтового ящика */
	const MAIL_INBOX_API = 'https://e.mail.ru/api/v1/threads/status/smart';
	/** @var int По сколько записей выводить страницу */
	const RECORDS_ON_PAGE = 25;
	/** @var int Идентификатор папки "Входящие" */
	const FOLDER_INBOX = 0;
	/** @var int Статус 200 ОК */
	const STATUS_OK = 200;
	/** @var string Название файла для cookies */
	const COOKIES_FILE = 'cookies.txt';

	/**
	 * Конструктор класса, устанавливаем базовые свойства
	 *
	 * @param string $email Email пользователя
	 * @param string $password Пароль пользователя
	 * @return void
	 */
	public function __construct(string $email, string $password)
	{
		$params = explode('@', $email);
		$this->email = $email;
		$this->password = $password;
		$this->login = $params[0];
		$this->domain = strtolower($params[1]);
	}

	/**
	 * Подключаемся к почтовому ящику
	 * Сохраняем ответ от сервера в $this->response
	 *
	 * @return bool
	 */
	public function connect(): bool
	{
		$fields = [
			'Login' => $this->login,
			'Domain' => $this->domain,
			'Password' => $this->password,
		];
	 
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, self::AUTH_URL);
		curl_setopt($curl, CURLOPT_COOKIEFILE, self::COOKIES_FILE);
		curl_setopt($curl, CURLOPT_COOKIEJAR, self::COOKIES_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		$response = curl_exec($curl);
		if ($response === false) {
			return false;
		}

		$h_len = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $h_len);
		$curlInfo = curl_getinfo($curl);
		$this->response = $response;
		$this->inboxUrl = $curlInfo['url'];
		
		curl_close($curl);

		return (bool) preg_match('/Set-Cookie: /i', $header);
	}

	/**
	 * Метод парсит токен в исходном коде страницы https://e.mail.ru/messages/inbox/
	 *
	 * @return array
	 */
	public function parseToken(): array
	{
		$token = '';
		if (strpos($this->response, 'patron.updateToken(') !== false) {
			preg_match('/patron.updateToken\("([^)]*)"\);.*/', $this->response, $matches);
			if (isset($matches[1]) && strlen($matches[1]) > 0) {
				$token = $matches[1];
			}
		}
		if ($token) {
			$this->setToken($token);
			return [
				'success' => true,
				'error' => '',
			];
		} else {
			return [
				'success' => false,
				'error' => 'ERROR. Не удалось распарсить token в исходном коде страницы https://e.mail.ru/messages/inbox/. Изменился исходный код или URL страницы',
			];
		}
	}

	/**
	 * Метод устанавливает token
	 *
	 * @param string $token Токен для получения данных из API
	 * @return void
	 */
	private function setToken(string $token)
	{
		$this->token = $token;
	}

	/**
	 * Метод отправляет запрос на https://e.mail.ru/api/v1/threads/status/smart для получение всех данных из почтового ящика
	 *
	 * @param int $page Страница пагинации
	 * @param int $limit Сколько сообщений выводить на странице, по умолчанию 25
	 * @return array
	 */
	public function getContent(int $page, int $limit = self::RECORDS_ON_PAGE): array
	{
		if (!$this->email) {
			return [
				'success' => false,
				'error' => 'ERROR. Некорректный email для запроса к ' . self::MAIL_INBOX_API,
			];
		}
		if (!$this->token) {
			return [
				'success' => false,
				'error' => 'ERROR. Некорректный token для ' . self::MAIL_INBOX_API,
			];
		}

		//Выбираем постраничное смещение
		if ($page <= 0) {
			$page = 1;
		}
		$offset = $limit * ($page - 1);

		//Параметры для запроса
		$params = [
			'email=' . urlencode($this->email),
			'offset=' . $offset,
			'limit=' . $limit,
			'folder=' . self::FOLDER_INBOX,
			'sort=%7B%22type%22%3A%22date%22%2C%22order%22%3A%22desc%22%7D',
			'token=' . $this->token,
		];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, self::MAIL_INBOX_API . '?' . implode('&', $params));
		curl_setopt($ch, CURLOPT_COOKIEFILE, self::COOKIES_FILE);
		curl_setopt($ch, CURLOPT_COOKIEJAR, self::COOKIES_FILE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		$response = curl_exec($ch);
		curl_close($ch);
		if ($response === false) {
			return [
				'success' => false,
				'error' => 'ERROR. Ошибка при отправке cURL-запрос на ' . self::MAIL_INBOX_API,
			];
		}

		return [
			'success' => true,
			'response' => json_decode($response),
			'error' => '',
		];
	}

	/**
	 * Метод валидирует email
	 *
	 * @param string $email Email для валидации
	 * @return bool
	 */
	public static function validateEmail(string $email): bool
	{
		if (filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strpos($email, '/') === false) {
			return true;
		}
		return false;
	}
}



//Начало логики
$email = 'batanik22@mail.ru';
$password = 'utofbmz4';
if (!MailRu::validateEmail($email)) {
	echo 'ERROR. Email ' . $email . ' не прошел валидацию.';
	exit();
}
if (!strlen(trim($password))) {
	echo 'ERROR. Пароль не может быть пустым.';
	exit();
}
$MailRu = new MailRu($email, $password);
if ($MailRu->connect()) {
	$parseToken = $MailRu->parseToken();
	if ($parseToken['success']) {
		$page = (int) $_GET['page'];
		if ($page <= 0) {
			$page = 1;
		}

		$result = $MailRu->getContent($page);
		if ($result['success'] && is_object($result['response'])) {
			if ($result['response']->status == MailRu::STATUS_OK) {
				if (is_array($result['response']->body->threads)) {
					$countThreads = count($result['response']->body->threads);
					if ($countThreads > 0) {
						echo '<h1>Список писем в почтовом ящике ' . $email . '</h1>';
						foreach ($result['response']->body->threads as $key => $threads) {
							$key++;

							//Выводим заголовок письма!
							echo  $key . '. ' . $threads->subject . '<br>';
						}
						
						echo '<br>';
						if ($page > 1) {
							echo '<a href="/test.php?page=' . ($page - 1) . '"><< Предыдущая страница</a> | ';
						}
						echo '<a href="/test.php?page=' . ($page + 1) . '">Следующая страница >></a>';
					} else {
						echo 'В этом почтовом ящике пока нет Входящих писем!';
					}
				}
			} else {
				echo 'ERROR. Ошибка при получении входящих сообщений с ' . self::MAIL_INBOX_API . '. Код ответа: ' . $result['response']->status;
			}
		} else {
			echo $result['error'];
		}
	} else {
		echo $parseToken['error'];
	}
} else {
	echo 'ERROR. Не удалось подключиться к почтовому ящику. Проверьте email + пароль и настройки соединения.';
}
exit();
