<?php

/**
 * Задача:
 * Сделать скрипт, который вытягивает все изображения из тегов <img и css-стилей со страницы http://mail.ru. 
 * Изображения в блоках <script> и в подключаемых стилях учитывать не нужно. 
 * Количество вызовов preg_match_all должно быть минимальным (в идеале один). 
 * Полученные урлы картинок либо их base64-представления вывести пронумерованным списком:
 * 1. url1;
 * 2. url2;
 * 3. ...
 */


/**
 * Класс для вытягивания изображений с другого сайта
 *
 * @author Dmitry Evtushenko
 * @version 1.0, 14.01.2020
 */
class ImageGrabber
{
	/** @var string Адрес страницы, с которой будем вытягивать изображения */
	private $parseUrl;
	/** @var string Ответ от сервера */
	private $response = '';
	/** @var bool Флаг, что в response можно парсить изображения */
	private $canParse = false;
	/** @var array Готовые изображения */
	private $images = [];
	/** @var string Текст ошибки */
	public $errorMessage = '';

	/**
	 * Конструктор класса, устанавливаем базовые свойства
	 *
	 * @param string $parseUrl Адрес исходной страницы
	 * @return void
	 */
	public function __construct(string $parseUrl)
	{
		$this->parseUrl = $parseUrl;
		$this->init();
	}

	/**
	 * Запускает Grabber
	 *
	 * @return void
	 */
	private function init()
	{
		if (!self::validateUrl($this->parseUrl)) {
			$this->errorMessage = 'ERROR. Строка "' . $this->parseUrl . '" не является URL-ом.';
			return;
		}

		$this->request();

		if ($this->response) {
			$this->removeTrash();
			$this->parseImages();
		}
	}

	/**
	 * Выполняет запрос через cUrl, записывает ответ в $this->response
	 *
	 * @return void
	 */
	private function request()
	{
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $this->parseUrl);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($curl);

		if ($response === false) {
			$info = curl_getinfo($curl);
			$this->errorMessage = 'ERROR. Ошибка при отправке cURL-запроса на ' . $this->parseUrl . ', код ответа: ' . $info['http_code'] . '.';
		}

		curl_close($curl);
		
		$this->response = $response;
	}

	/**
	 * Удаляет всё лишнее из $this->response
	 *
	 * @return void
	 */
	private function removeTrash()
	{
		//Вырезаем все теги <script> из ответа, чтоб не мешали
		$this->response = preg_replace('#<script.*?</script>#si', '', $this->response);

		$this->allowParsing();
	}

	/**
	 * Парсит картинки в ответе от сервера
	 *
	 * @return void
	 */
	private function parseImages()
	{
		if ($this->canParse) {
			preg_match_all('/(src=|url\()(\"|\')?(((https?:)?\/\/([\/\w\-\.@]+)\.(png|jpe?g|gif|svg))|(data:image\S+(=|\+)))/i', $this->response, $media);

			if (is_array($media[3]) && count($media[3]) > 0) {
				//Берем группу №3, если нужна уникальность, сделать array_unique($media[3])
				$this->images = $media[3];
			} else {
				$this->errorMessage = 'ERROR. На странице ' . $this->parseUrl . ' изображения не найдены.';
			}
		} else {
			$this->errorMessage = 'ERROR. Предотвращена попытка парсить изображения в неподготовленных данных.';
		}
	}

	/**
	 * Разрешает парсить $this->response
	 *
	 * @return void
	 */
	private function allowParsing()
	{
		$this->canParse = true;
	}

	/**
	 * Возвращает найденные картинки
	 *
	 * @return array
	 */
	public function getImages(): array
	{
		return $this->images;
	}
	
	/**
	 * Проверяем, является ли входящая строка URL-ом
	 *
	 * @param string $url Входящая строка
	 * @return bool
	 */
	private static function validateUrl(string $str): bool
	{
		if (preg_match("/^(http|https):\/\/((www\.)?([a-zа-я0-9_-]+\.)?([a-zа-я0-9]([-a-zа-я0-9]{0,61}[a-zа-я0-9]))(\.)(com|ru|рф|[a-z])([a-zа-я0-9_\-\.\/\?=%&;\#]*[^\s.,<>()\]:\-'\"!?]+)?)$/i", $str)) {
			return true;
		}
		return false;
	}
}


//Начало логики
$page = 'https://mail.ru/';
$ImageGrabber = new ImageGrabber($page);
$images = $ImageGrabber->getImages();

if (is_array($images) && count($images) > 0) {
	echo '<h1>Список изображений со страницы ' . $page . '</h1>';

	$key = 0;
	foreach ($images as $url) {
		$key++;
		echo $key . '. ' . $url . '<br>';
	}
} else {
	echo $ImageGrabber->errorMessage;
}

exit();
