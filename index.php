<?php
declare(strict_types=1);

use Bracelet\{WebhookProcessor, InvalidIpException, InvalidTokenException, OversizedBodyException, BadRequestException};
use function Bracelet\logError;

// Путь к автозагрузчику Composer.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // При отсутствии autoload фиксируем проблему через системный лог
    // и уведомляем о необходимости установки зависимостей.
    error_log('Не найден autoload Composer. Выполни "composer install".');
    throw new RuntimeException('Файл autoload.php отсутствует');
}
require_once $autoload;

// Подключаем класс обработчика webhook-запроса и конфигурацию бота.
require __DIR__ . '/bracelet/webhook.php';
$config = require __DIR__ . '/bracelet/config.php';

/**
 * Создаём обработчик и запускаем обработку входящего HTTP-запроса Telegram.
 *
 * Оборачиваем основные действия в блоки try...catch, чтобы корректно
 * реагировать на различные типы ошибок и устанавливать соответствующие
 * HTTP‑коды ответа. Все сообщения об ошибках фиксируются в логах.
 */
try {
    // Инициализируем обработчик webhook-запросов.
    $processor = new WebhookProcessor($config); // new WebhookProcessor()
    // Запускаем непосредственную обработку входящего HTTP-запроса.
    $processor->handle();
} catch (InvalidIpException|InvalidTokenException $e) {
    // Запрос отклонён: IP не принадлежит Telegram или токен неверен.
    logError('Запрос отклонён: ' . $e->getMessage());
    http_response_code(403); // Недостаточно прав для выполнения запроса.
} catch (OversizedBodyException $e) {
    // Размер тела запроса превышает допустимый лимит.
    logError('Слишком большой запрос: ' . $e->getMessage());
    http_response_code(413); // Слишком большой запрос.
} catch (BadRequestException $e) {
    // Ошибка в формате запроса: пустое тело, неверный JSON и т.п.
    logError('Некорректный запрос: ' . $e->getMessage());
    http_response_code(400); // Некорректный запрос клиента.
    echo $e->getMessage();
}
