<?php

namespace Bracelet;

use RuntimeException;
use function loadBotEnv;

require_once __DIR__ . '/../loadEnv.php'; // Подключаем функцию загрузки .env-файлов
loadBotEnv('bracelet'); // Загружаем переменные из `.env.bracelet`

/**
 * Возвращает значение переменной окружения или выбрасывает исключение,
 * если переменная отсутствует или пуста.
 *
 * @param string $name Имя необходимой переменной окружения.
 *
 * @return string Значение переменной окружения.
 *
 * @throws RuntimeException Если переменная не задана или имеет пустое значение.
 */
function getEnvOrFail(string $name): string
{
    $value = $_ENV[$name] ?? getenv($name);

    if ($value === false || $value === null || $value === '') {
        logError("Отсутствует переменная окружения {$name}");
        throw new RuntimeException("Переменная окружения {$name} не задана");
    }

    return $value;
}

// Токен Telegram-бота. Без него невозможна работа с Telegram API
define('BOT_TOKEN', getEnvOrFail('BOT_TOKEN'));

// Имя базы данных Postgres
define('DB_NAME', getEnvOrFail('DB_NAME'));

// Имя пользователя базы данных
define('DB_USER', getEnvOrFail('DB_USER'));

// Пароль пользователя базы данных
define('DB_PASSWORD', getEnvOrFail('DB_PASSWORD'));

// Хост сервера базы данных. Если не указан — используем localhost
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');

// Порт сервера базы данных. По умолчанию Postgres слушает порт 5432
define('DB_PORT', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432');

/**
 * Строка подключения к базе данных.
 *
 * @var string
 */
define('DB_DSN', $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME);

/**
 * Базовый URL для запросов к Telegram API. Значение обязательно.
 *
 * @var string
 */
define('API_URL', $_ENV['API_URL'] ?? getenv('API_URL') ?: 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
