<?php
/**
 * Точка входа для HTTP-запросов к тестовому боту.
 *
 * Подключает автозагрузчик Composer и делегирует выполнение
 * скрипту webhook.php, который содержит основную логику бота.
 *
 * PHP 8.0+
 *
 * @throws RuntimeException при отсутствии файла autoload.php
 */
declare(strict_types=1);

// Путь к автозагрузчику Composer.
$autoload = __DIR__ . '/../vendor/autoload.php';

// Проверяем наличие файла, чтобы избежать непонятных ошибок
// при запуске приложения без зависимостей.
if (!file_exists($autoload)) {
    throw new RuntimeException(
        'Файл autoload.php не найден. Выполните "composer install" перед запуском.'
    );
}

// Подключаем автозагрузчик Composer.
require_once $autoload;

// Передаём управление файлу webhook.php, где реализована логика бота.
require __DIR__ . '/webhook.php';
