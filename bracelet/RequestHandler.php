<?php

declare(strict_types=1);

namespace Bracelet;

/**
 * Класс, отвечающий за чтение и валидацию входящего запроса от Telegram.
 *
 * Выполняет проверку IP-адреса отправителя, секретного токена,
 * размера тела запроса и корректность JSON.
 */
class RequestHandler
{
    /**
     * Флаг доверия к заголовку `X-Forwarded-For`.
     * Если `true`, IP-адрес может быть взят из этого заголовка.
     */
    private bool $trustForwarded;

    /**
     * Максимально допустимый размер тела запроса в байтах.
     */
    private int $maxBodySize;

    /**
     * @param bool $trustForwarded Доверять ли заголовку `X-Forwarded-For`.
     * @param int  $maxBodySize    Максимально допустимый размер тела запроса в байтах.
     */
    public function __construct(bool $trustForwarded = false, int $maxBodySize = 1048576)
    {
        $this->trustForwarded = $trustForwarded;
        $this->maxBodySize = $maxBodySize; // 1 МБ по умолчанию
    }

    /**
     * Считывает и валидирует запрос, возвращая данные сообщения.
     *
     * @return array{message: array, chatId: int, userId: int, userLang: string} Данные сообщения пользователя.
     */
    public function handle(): array
    {
        // Получаем заголовки и приводим их к нижнему регистру для единообразия.
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case($headers, CASE_LOWER);

        // Определяем IP-адрес отправителя с учётом возможного прокси.
        $remoteIp = resolveRemoteIp($headers, $_SERVER, $this->trustForwarded);

        // Разрешаем запросы только от Telegram.
        if (!isTelegramIP($remoteIp)) {
            logError('Недопустимый запрос: IP ' . $remoteIp);
            http_response_code(403);
            exit;
        }

        // Проверяем секретный токен, если он задан.
        $secret = $_ENV['WEBHOOK_SECRET'] ?? getenv('WEBHOOK_SECRET') ?: '';
        if ($secret !== '') {
            if (!isset($headers['x-telegram-bot-api-secret-token']) ||
                !hash_equals($secret, $headers['x-telegram-bot-api-secret-token'])) {
                $token = $headers['x-telegram-bot-api-secret-token'] ?? '';
                $maskedToken = $token !== '' ? substr($token, 0, 4) . '***' : '';
                logError('Недопустимый токен: ' . $maskedToken . ', IP ' . $remoteIp);
                http_response_code(403);
                exit;
            }
        }

        // Проверяем указанный клиентом размер тела.
        $contentLengthHeader = (int)($headers['content-length'] ?? ($_SERVER['CONTENT_LENGTH'] ?? 0));
        if ($contentLengthHeader > $this->maxBodySize) {
            logError('Превышен допустимый размер запроса по заголовку: ' . $contentLengthHeader . ' байт');
            http_response_code(413);
            exit;
        }

        // Считываем тело запроса из входного потока.
        $body = file_get_contents('php://input');
        // Фиксируем тело запроса и IP отправителя для отладки.
        logInfo('Получено тело запроса от IP ' . $remoteIp . ': ' . $body);

        // Проверяем реальный размер полученных данных.
        if (strlen($body) > $this->maxBodySize) {
            logError('Превышен допустимый размер запроса: ' . strlen($body) . ' байт');
            http_response_code(413);
            exit;
        }

        // Тело не должно быть пустым.
        if (trim($body) === '') {
            $errorMessage = 'Пустое тело запроса';
            logError($errorMessage . ', IP ' . $remoteIp);
            http_response_code(400);
            echo $errorMessage;
            exit;
        }

        // Пытаемся декодировать JSON.
        $update = json_decode($body, true);
        if ($update === null && json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = 'Некорректный JSON: ' . json_last_error_msg();
            logError($errorMessage . '; данные: ' . $body . '; IP ' . $remoteIp);
            http_response_code(400);
            echo $errorMessage;
            exit;
        }

        // Убедимся, что в обновлении есть ключ `message`.
        if (!isset($update['message'])) {
            logError(
                'Некорректное обновление: поле message отсутствует. Полный JSON: '
                . json_encode($update, JSON_UNESCAPED_UNICODE)
            );
            exit;
        }

        $msg = $update['message'];
        $decodedMsg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        logInfo('Получено сообщение: ' . $decodedMsg . '; IP ' . $remoteIp);

        $chatId = $msg['chat']['id'];
        $userId = $msg['from']['id'];
        $userLang = $msg['from']['language_code'] ?? 'ru';

        return [
            'message' => $msg,
            'chatId' => $chatId,
            'userId' => $userId,
            'userLang' => $userLang,
        ];
    }
}
