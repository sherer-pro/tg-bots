<?php
/**
 * Подключение автозагрузчика Composer с проверкой наличия.
 *
 * @throws RuntimeException если autoload отсутствует.
 */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    logError('Не найден autoload Composer. Выполни "composer install".');
    throw new RuntimeException('Файл autoload.php отсутствует');
}
require_once $autoload;

/**
 * Точка входа для HTTP-запросов к корню домена.
 * Просто делегирует обработку webhook-скрипту бота.
 */
require __DIR__ . '/bracelet/webhook.php';
