<?php
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
    throw new RuntimeException('Переменная окружения BOT_TOKEN не задана');
}
define('BOT_TOKEN', $botToken);

/**
 * Хост, на котором размещено веб-приложение.
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
 * @var string
 */
define('WEBAPP_URL', $_ENV['WEBAPP_URL'] ?? getenv('WEBAPP_URL') ?: 'https://' . HOST . '/bracelet/webapp/index.html');

/**
 * Путь к файлу логов приложения.
 *
 * Лог хранится в каталоге `bracelet/logs` и используется для записи
 * детальной информации об ошибках, чтобы не засорять стандартный вывод.
 * При отсутствии каталога он будет создан автоматически.
 *
 * @var string
 */
define('LOG_FILE', __DIR__ . '/logs/app.log');

