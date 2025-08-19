<?php

declare(strict_types=1);

namespace Bracelet;

/**
 * Класс-обёртка для работы с API Telegram.
 *
 * Содержит метод отправки сообщений с базовой обработкой ошибок
 * уровня cURL и HTTP.
 */
class TelegramApi
{
    /** @var string Базовый URL Telegram API */
    private string $apiUrl;

    /**
     * @param Config $config Объект конфигурации с параметрами приложения.
     */
    public function __construct(Config $config)
    {
        // Сохраняем базовый URL API для дальнейшего использования.
        $this->apiUrl = $config->apiUrl;
    }

    /**
     * Отправляет сообщение пользователю.
     *
     * @param string     $text  Текст сообщения.
     * @param int|string $chat  ID чата или @username получателя.
     * @param array      $extra Дополнительные параметры API.
     *
     * @return bool true при успешной отправке.
     */
    public function send(string $text, int|string $chat, array $extra = []): bool
    {
        // Формируем конечный URL запроса к Telegram API.
        $url  = $this->apiUrl . 'sendMessage';
        $data = array_merge(['chat_id' => $chat, 'text' => $text], $extra);

        // Инициализируем cURL для отправки POST-запроса.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,                     // Используем метод POST
            CURLOPT_POSTFIELDS     => http_build_query($data),  // Тело запроса в формате key=value
            CURLOPT_RETURNTRANSFER => true,                     // Возвращаем ответ как строку
            CURLOPT_CONNECTTIMEOUT => 5,                        // Ждём соединение не более 5 секунд
            CURLOPT_TIMEOUT        => 10,                       // Общее ожидание ответа — до 10 секунд
        ]);

        $response = curl_exec($ch);

        // Проверяем наличие ошибок на уровне cURL.
        if ($response === false) {
            logError('Ошибка cURL: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Анализируем HTTP-статус ответа.
        if ($httpCode < 200 || $httpCode >= 300) {
            logError('Ошибка HTTP: статус ' . $httpCode . '; ответ: ' . $response);
            return false;
        }

        // Декодируем JSON-ответ Telegram.
        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            logError('Некорректный JSON в ответе API: ' . $response);
            return false;
        }

        // Проверяем наличие и значение флага `ok`.
        if (!isset($decoded['ok']) || $decoded['ok'] === false) {
            $desc = $decoded['description'] ?? 'неизвестная ошибка';
            logError('Ошибка API Telegram: ' . $desc);
            return false;
        }

        return true; // Всё прошло успешно
    }
}
