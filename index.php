<?php
declare(strict_types=1);

use function Bracelet\runWebhook;

// Путь к автозагрузчику Composer.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // При отсутствии autoload фиксируем проблему через системный лог
    // и уведомляем о необходимости установки зависимостей.
    error_log('Не найден autoload Composer. Выполни "composer install".');
    throw new RuntimeException('Файл autoload.php отсутствует');
}
require_once $autoload;

// Подключаем скрипт вебхука, определяющий функцию runWebhook.
require __DIR__ . '/bracelet/webhook.php';

/**
 * Запускаем обработку входящего HTTP-запроса Telegram.
 */
runWebhook();
