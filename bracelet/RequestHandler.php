<?php

declare(strict_types=1);

namespace Bracelet;

/**
 * Исключение, выбрасываемое при запросе с недопустимого IP.
 */
class InvalidIpException extends \Exception {}

/**
 * Исключение, сигнализирующее о некорректном секретном токене.
 */
class InvalidTokenException extends \Exception {}

/**
 * Исключение, обозначающее превышение допустимого размера тела запроса.
 */
class OversizedBodyException extends \Exception {}

/**
 * Исключение для всех ошибок формата входящего запроса.
 */
class BadRequestException extends \Exception {}

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
     * Считывает и валидирует запрос, возвращая DTO с данными сообщения.
     *
     * @return Request Объект, содержащий все необходимые данные входящего сообщения.
     *
     * @throws InvalidIpException      Если IP-адрес не принадлежит Telegram.
     * @throws InvalidTokenException   При некорректном секретном токене.
     * @throws OversizedBodyException  Если размер тела превышает допустимый.
     * @throws BadRequestException     При пустом теле, ошибочном JSON или отсутствии поля `message`.
     */
    public function handle(): Request
    {
        // Получаем заголовки и приводим их к нижнему регистру для единообразия.
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case($headers, CASE_LOWER);

        // Определяем IP-адрес отправителя с учётом возможного прокси.
        $remoteIp = resolveRemoteIp($headers, $_SERVER, $this->trustForwarded);

        // Разрешаем запросы только от Telegram.
        if (!isTelegramIP($remoteIp)) {
            logError('Недопустимый запрос: IP ' . $remoteIp);
            // Сообщаем вызывающему коду о недопустимом IP.
            throw new InvalidIpException('Недопустимый IP-адрес: ' . $remoteIp);
        }

        // Проверяем секретный токен, если он задан.
        $secret = $_ENV['WEBHOOK_SECRET'] ?? getenv('WEBHOOK_SECRET') ?: '';
        if ($secret !== '') {
            if (!isset($headers['x-telegram-bot-api-secret-token']) ||
                !hash_equals($secret, $headers['x-telegram-bot-api-secret-token'])) {
                $token       = $headers['x-telegram-bot-api-secret-token'] ?? '';
                $maskedToken = $token !== '' ? substr($token, 0, 4) . '***' : '';
                logError('Недопустимый токен: ' . $maskedToken . ', IP ' . $remoteIp);
                // При некорректном токене прекращаем обработку.
                throw new InvalidTokenException('Некорректный секретный токен');
            }
        }

        // Проверяем указанный клиентом размер тела.
        $contentLengthHeader = (int)($headers['content-length'] ?? ($_SERVER['CONTENT_LENGTH'] ?? 0));
        if ($contentLengthHeader > $this->maxBodySize) {
            logError('Превышен допустимый размер запроса по заголовку: ' . $contentLengthHeader . ' байт');
            // Размер, заявленный клиентом, уже больше допустимого.
            throw new OversizedBodyException('Размер тела по заголовку слишком велик');
        }

        // Считываем тело запроса из входного потока.
        $body = file_get_contents('php://input');
        // Фиксируем тело запроса и IP отправителя для отладки.
        logInfo('Получено тело запроса от IP ' . $remoteIp . ': ' . $body);

        // Проверяем реальный размер полученных данных.
        if (strlen($body) > $this->maxBodySize) {
            logError('Превышен допустимый размер запроса: ' . strlen($body) . ' байт');
            // Фактический размер тела превышает ограничение.
            throw new OversizedBodyException('Размер тела превышает допустимый лимит');
        }

        // Тело не должно быть пустым.
        if (trim($body) === '') {
            $errorMessage = 'Пустое тело запроса';
            logError($errorMessage . ', IP ' . $remoteIp);
            // Отсутствие данных считается ошибочным запросом.
            throw new BadRequestException($errorMessage);
        }

        // Пытаемся декодировать JSON.
        $update = json_decode($body, true);
        if ($update === null && json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = 'Некорректный JSON: ' . json_last_error_msg();
            logError($errorMessage . '; данные: ' . $body . '; IP ' . $remoteIp);
            // Не удалось распарсить JSON — это ошибка клиента.
            throw new BadRequestException($errorMessage);
        }

        // Убедимся, что в обновлении есть ключ `message`.
        if (!isset($update['message'])) {
            logError(
                'Некорректное обновление: поле message отсутствует. Полный JSON: '
                . json_encode($update, JSON_UNESCAPED_UNICODE)
            );
            // Клиент прислал данные без обязательного блока message.
            throw new BadRequestException('Отсутствует обязательное поле message');
        }

        $msg = $update['message'];
        $decodedMsg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        logInfo('Получено сообщение: ' . $decodedMsg . '; IP ' . $remoteIp);

        $chatId = $msg['chat']['id'];
        $userId = $msg['from']['id'];
        $userLang = $msg['from']['language_code'] ?? 'ru';

        // Возвращаем DTO с заполненными полями.
        return new Request($msg, $chatId, $userId, $userLang);
    }
}

