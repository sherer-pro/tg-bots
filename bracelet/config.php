<?php
/**
 * Токен Telegram-бота.
 *
 * @var string
 */
define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN') ?: '');

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
 */
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'bracelet_user');

/**
 * Пароль пользователя базы данных.
 *
 * @var string
 */
define('DB_PASSWORD', $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: 'secret');

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
 * Базовый URL для запросов к Telegram API.
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
 *
 * @var string
 */
define('LOG_FILE', __DIR__ . '/logs/app.log');

