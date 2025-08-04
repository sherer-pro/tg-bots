<?php
require_once __DIR__ . '/logger.php'; // Подключаем функции логирования
require_once __DIR__ . '/../loadEnv.php'; // Подключаем функцию загрузки .env-файлов
loadBotEnv('bracelet'); // Загружаем переменные из `.env.bracelet`

/**
 * Токен Telegram-бота. Обязательное значение.
 *
 * @var string
 *
 * @throws RuntimeException Если переменная окружения не задана.
 */
$botToken = $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN');
if ($botToken === false || $botToken === null || $botToken === '') {
    // Без токена бот не сможет обращаться к Telegram API
    logError('Отсутствует переменная окружения BOT_TOKEN');
    throw new RuntimeException('Переменная окружения BOT_TOKEN не задана');
}
define('BOT_TOKEN', $botToken);

/**
 * Хост, на котором размещено веб-приложение.
 * Необходим для корректного формирования ссылок мини‑аппа.
 *
 * @var string
 */
define('HOST', $_ENV['HOST'] ?? getenv('HOST') ?: '');

/**
 * Имя базы данных Postgres.
 *
 * @var string
 */
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '');

/**
 * Пользователь базы данных.
 *
 * @var string
 *
 * @throws RuntimeException Если переменная окружения не задана.
 */
$dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER');
if ($dbUser === false || $dbUser === null || $dbUser === '') {
    logError('Отсутствует переменная окружения DB_USER');
    throw new RuntimeException('Переменная окружения DB_USER не задана');
}
define('DB_USER', $dbUser);

/**
 * Пароль пользователя базы данных.
 *
 * @var string
 *
 * @throws RuntimeException Если переменная окружения не задана.
 */
$dbPassword = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD');
if ($dbPassword === false || $dbPassword === null || $dbPassword === '') {
    logError('Отсутствует переменная окружения DB_PASSWORD');
    throw new RuntimeException('Переменная окружения DB_PASSWORD не задана');
}
define('DB_PASSWORD', $dbPassword);

/**
 * Хост сервера базы данных.
 *
 * @var string
 */
$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';

/**
 * Порт сервера базы данных.
 *
 * @var string
 */
$dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';

/**
 * Строка подключения к базе данных.
 *
 * @var string
 */
define('DB_DSN', $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: 'pgsql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . DB_NAME);

/**
 * Базовый URL для запросов к Telegram API. Значение обязательно.
 *
 * @var string
 */
define('API_URL', $_ENV['API_URL'] ?? getenv('API_URL') ?: 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

/**
 * URL веб-приложения.
 *
 * Формируется из переменной окружения `WEBAPP_URL`, либо, при её отсутствии,
 * из `HOST` или `$_SERVER['HTTP_HOST']` и относительного пути к мини-приложению.
 * Если определить хост не удаётся, генерировать корректный URL невозможно,
 * поэтому выбрасывается исключение.
 *
 * @var string
 *
 * @throws RuntimeException Если определить хост не удалось.
 */
$webappUrl = $_ENV['WEBAPP_URL'] ?? getenv('WEBAPP_URL');
if ($webappUrl === false || $webappUrl === null || $webappUrl === '') {
    // Пытаемся получить хост из переменной окружения или из заголовка HTTP_HOST
    $host = HOST;
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            // Сообщаем в лог, что используем HTTP_HOST вместо переменной окружения
            logError('Переменная окружения HOST не задана. Используем значение $_SERVER["HTTP_HOST"].');
        } else {
            logError('Не удалось определить хост: переменная окружения HOST и $_SERVER["HTTP_HOST"] отсутствуют.');
            throw new RuntimeException('Не удалось определить хост для WEBAPP_URL');
        }
    }
    // Формируем URL из найденного хоста и стандартного пути к мини-приложению
    $webappUrl = 'https://' . $host . '/bracelet/webapp/index.html';
}
define('WEBAPP_URL', $webappUrl);

