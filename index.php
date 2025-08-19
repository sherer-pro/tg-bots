<?php
declare(strict_types=1);

use Bracelet\WebhookProcessor;

// Путь к автозагрузчику Composer.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // При отсутствии autoload фиксируем проблему через системный лог
    // и уведомляем о необходимости установки зависимостей.
    error_log('Не найден autoload Composer. Выполни "composer install".');
    throw new RuntimeException('Файл autoload.php отсутствует');
}
require_once $autoload;

// Подключаем класс обработчика webhook-запроса.
require __DIR__ . '/bracelet/webhook.php';

/**
 * Создаём обработчик и запускаем обработку входящего HTTP-запроса Telegram.
 */
(new WebhookProcessor())->handle();
