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
 * Имя базы данных Postgres.
 *
 * @var string
 *
 * @throws RuntimeException Если переменная окружения не задана.
 */
$dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
if ($dbName === false || $dbName === null || $dbName === '') {
    // Без имени базы данных невозможно установить соединение
    logError('Отсутствует переменная окружения DB_NAME');
    throw new RuntimeException('Переменная окружения DB_NAME не задана');
}
define('DB_NAME', $dbName);

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
