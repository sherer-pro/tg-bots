<?php

declare(strict_types=1);

namespace Bracelet;

use RuntimeException;
use function loadBotEnv;

require_once __DIR__ . '/../loadEnv.php'; // Подключаем функцию загрузки .env-файлов
loadBotEnv('bracelet'); // Загружаем переменные из `.env.bracelet`

/**
 * Класс с параметрами конфигурации приложения.
 *
 * Все значения читаются из переменных окружения при подключении файла и
 * становятся доступными только для чтения через публичные свойства.
 */
final class Config
{
    /** @var string Токен Telegram-бота */
    public readonly string $botToken;

    /** @var string Имя базы данных */
    public readonly string $dbName;

    /** @var string Пользователь базы данных */
    public readonly string $dbUser;

    /** @var string Пароль пользователя базы данных */
    public readonly string $dbPassword;

    /** @var string Хост сервера базы данных */
    public readonly string $dbHost;

    /** @var string Порт сервера базы данных */
    public readonly string $dbPort;

    /** @var string Строка подключения к базе данных */
    public readonly string $dbDsn;

    /** @var string Базовый URL Telegram API */
    public readonly string $apiUrl;

    /**
     * @param string $botToken   Токен Telegram-бота.
     * @param string $dbName     Имя базы данных.
     * @param string $dbUser     Имя пользователя БД.
     * @param string $dbPassword Пароль пользователя БД.
     * @param string $dbHost     Хост сервера БД.
     * @param string $dbPort     Порт сервера БД.
     * @param string $dbDsn      Готовая строка подключения к БД.
     * @param string $apiUrl     Базовый URL Telegram API.
     */
    public function __construct(
        string $botToken,
        string $dbName,
        string $dbUser,
        string $dbPassword,
        string $dbHost,
        string $dbPort,
        string $dbDsn,
        string $apiUrl
    ) {
        $this->botToken   = $botToken;
        $this->dbName     = $dbName;
        $this->dbUser     = $dbUser;
        $this->dbPassword = $dbPassword;
        $this->dbHost     = $dbHost;
        $this->dbPort     = $dbPort;
        $this->dbDsn      = $dbDsn;
        $this->apiUrl     = $apiUrl;
    }
}

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

// Получаем и нормализуем необходимые значения окружения.
$botToken   = getEnvOrFail('BOT_TOKEN');
$dbName     = getEnvOrFail('DB_NAME');
$dbUser     = getEnvOrFail('DB_USER');
$dbPassword = getEnvOrFail('DB_PASSWORD');
$dbHost     = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$dbPort     = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
$dbDsn      = $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: 'pgsql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName;
$apiUrl     = $_ENV['API_URL'] ?? getenv('API_URL') ?: 'https://api.telegram.org/bot' . $botToken . '/';

// Возвращаем готовый объект конфигурации.
return new Config($botToken, $dbName, $dbUser, $dbPassword, $dbHost, $dbPort, $dbDsn, $apiUrl);
