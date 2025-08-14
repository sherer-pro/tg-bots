<?php

declare(strict_types=1);

// Подключаем автозагрузчик Composer, чтобы можно было использовать библиотеку vlucas/phpdotenv.
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

/**
 * Загружает переменные окружения для конкретного Telegram-бота.
 *
 * Функция ищет файл `.env.<имя_бота>` в корневом каталоге проекта и
 * считывает переменные окружения с помощью библиотеки `vlucas/phpdotenv`.
 * При отсутствии файла выбрасывается исключение с понятным описанием.
 *
 * @param string $botName Имя бота, которое соответствует суффиксу в названии файла.
 *
 * @throws RuntimeException Если файл с переменными окружения не найден.
 *
 * @return void
 */
function loadBotEnv(string $botName): void
{
    // Формируем имя файла вида `.env.<botName>`.
    $envFile = ".env.$botName";
    $basePath = __DIR__;

    // Проверяем существование файла, чтобы не получить трудно отлавливаемую ошибку от Dotenv.
    if (!file_exists($basePath . DIRECTORY_SEPARATOR . $envFile)) {
        throw new RuntimeException("Файл окружения {$envFile} не найден");
    }

    // Загружаем переменные окружения из указанного файла.
    $dotenv = Dotenv::createImmutable($basePath, $envFile);
    $dotenv->load();
}
