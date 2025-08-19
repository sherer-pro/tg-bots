<?php
/**
 * Простой пример Telegram-бота, работающего через веб-хук.
 *
 * Скрипт получает сообщение от пользователя и отправляет
 * ответ с тем же текстом, предваряя его строкой "Вы написали:".
 *
 * Для работы необходимо задать токен бота в переменной окружения BOT_TOKEN.
 */
declare(strict_types=1);

// Получаем токен бота из переменной окружения.
$token = $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN');
if ($token === false || $token === '') {
    // Без токена мы не сможем обращаться к API Telegram.
    http_response_code(500);
    echo 'Не задан токен BOT_TOKEN';
    exit;
}

// Базовый URL Telegram API для текущего бота.
$apiUrl = "https://api.telegram.org/bot{$token}/";

// Читаем тело запроса, которое содержит обновление от Telegram.
$updateJson = file_get_contents('php://input');
if ($updateJson === false || trim($updateJson) === '') {
    // Если тело пустое, дальнейшая обработка не имеет смысла.
    exit;
}

// Преобразуем JSON в ассоциативный массив.
$update = json_decode($updateJson, true);
if ($update === null || !isset($update['message'])) {
    // Если JSON некорректен или в нём отсутствует сообщение, завершаем работу.
    exit;
}

// Извлекаем данные о чате и тексте сообщения пользователя.
$rawChatId = $update['message']['chat']['id'] ?? null;
$text      = $update['message']['text'] ?? '';
if ($rawChatId === null || (!is_int($rawChatId) && !(is_string($rawChatId) && preg_match('/^-?\d+$/', $rawChatId)))) {
    // Без корректного идентификатора чата невозможно отправить ответ.
    exit;
}
// Приводим возможную строку к целому числу.
$chatId = (int) $rawChatId;

// Формируем текст ответа.
$responseText = 'Вы написали: ' . $text;

// Отправляем сообщение обратно пользователю.
sendMessage($apiUrl, $chatId, $responseText);

/**
 * Отправляет текстовое сообщение в чат Telegram.
 *
 * @param string     $apiUrl  Базовый URL API Telegram.
 * @param int|string $chatId  Идентификатор чата или имя пользователя.
 * @param string     $text    Текст, который необходимо отправить.
 *
 * @return void
 */
function sendMessage(string $apiUrl, int|string $chatId, string $text): void
{
    $url = $apiUrl . 'sendMessage';

    // Параметры POST-запроса.
    $postData = [
        'chat_id' => $chatId,
        'text'    => $text,
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return; // Не удалось инициализировать cURL
    }

    // Настраиваем параметры cURL для отправки POST-запроса.
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);

    // Выполняем запрос и игнорируем ответ, так как боту не требуется
    // анализировать его в рамках данного примера.
    curl_exec($ch);
    curl_close($ch);
}
