<?php

declare(strict_types=1);
require_once __DIR__ . '/logger.php'; // Функции логирования доступны сразу

try {
    // Пытаемся подключить конфигурацию, где читаются переменные окружения
    require 'config.php';
} catch (Throwable $e) {
    // Если при загрузке конфигурации возникла ошибка,
    // фиксируем её в логе и прекращаем выполнение
    logError('Ошибка конфигурации: ' . $e->getMessage());
    exit;
}

require_once 'calc.php'; // Подключаем функции расчёта браслета
// Функции проверки IP-адресов Telegram вынесены в отдельный файл,
// чтобы их можно было переиспользовать и тестировать изолированно.
require_once __DIR__ . '/telegram_ip.php'; // Подключаем функции проверки IP

/**
 * Определяет IP-адрес клиента, учитывая возможное использование прокси.
 *
 * Перед поиском необходимых значений имена всех заголовков приводятся к
 * нижнему регистру, чтобы избежать зависимости от регистра.
 *
 * Последовательность выбора следующая:
 * 1. Если заголовок `X-Forwarded-For` присутствует, берётся первый IP из
 *    перечисленных, так как он соответствует исходному отправителю.
 * 2. Если заголовок отсутствует, используется `REMOTE_ADDR` из `$_SERVER`.
 *
 * @param array<string, string> $headers Заголовки входящего HTTP-запроса.
 * @param array<string, mixed>  $server  Данные о запросе, обычно `$_SERVER`.
 *
 * @return string IP-адрес клиента или пустая строка.
 */
function resolveRemoteIp(array $headers, array $server): string
{
    // Приводим имена заголовков к нижнему регистру для унификации
    $normalized = array_change_key_case($headers, CASE_LOWER);

    // Используем нормализованный массив для поиска исходного IP
    if (!empty($normalized['x-forwarded-for'])) {
        $forwarded = explode(',', $normalized['x-forwarded-for']);
        return trim($forwarded[0]);
    }

    // Если прокси-заголовок отсутствует, возвращаем REMOTE_ADDR
    return $server['REMOTE_ADDR'] ?? '';
}

if (!defined('WEBHOOK_LIB')):

// Получаем заголовки и определяем IP-адрес отправителя
$headers  = function_exists('getallheaders') ? getallheaders() : [];
/**
 * Приводим имена заголовков к нижнему регистру, чтобы исключить зависимость
 * от регистра, в котором веб-сервер возвращает заголовки. Это обеспечивает
 * корректный доступ к значениям при последующей проверке токена и других
 * операций с заголовками.
 */
$headers  = array_change_key_case($headers, CASE_LOWER);
$remoteIp = resolveRemoteIp($headers, $_SERVER);

/**
 * Секретный токен, заданный в переменных окружения.
 * Отсутствие значения допустимо только в тестовой среде.
 *
 * @var string $secret
 */
$secret = $_ENV['WEBHOOK_SECRET'] ?? getenv('WEBHOOK_SECRET') ?: '';

if ($secret === '') {
    // В тестовом окружении значение токена может отсутствовать.
    // В этом случае проверку токена отключаем, чтобы было удобно отлаживать
    // бота без передачи дополнительных заголовков.
    // ⚠ В боевой среде отключение проверки позволит злоумышленникам
    // отправлять поддельные запросы к нашему webhook и выполнять
    // несанкционированные операции.
    $tokenValid = true;
} else {
    // Сравниваем ожидаемый токен с тем, что пришёл в запросе от Telegram.
    $tokenValid = isset($headers['x-telegram-bot-api-secret-token'])
        && hash_equals($secret, $headers['x-telegram-bot-api-secret-token']);
}

// Проверяем, что запрос пришёл от Telegram (по токену или IP)
if (!$tokenValid && !isTelegramIP($remoteIp)) {
    // Записываем информацию о недопустимом запросе в лог, включая токен из заголовков
    logError('Недопустимый запрос: IP ' . $remoteIp . ', токен: ' . ($headers['x-telegram-bot-api-secret-token'] ?? ''));
    http_response_code(403);
    exit;
}

// Считываем тело запроса и пытаемся декодировать JSON
$body   = file_get_contents('php://input');
$update = json_decode($body, true);
if ($update === null && json_last_error() !== JSON_ERROR_NONE) {
    // Если JSON некорректен, фиксируем это и возвращаем 400
    logError('Некорректный JSON во входящем запросе: ' . $body);
    http_response_code(400);
    exit;
}
if (!isset($update['message'])) {
    exit;
}
$msg    = $update['message'];
$chatId = $msg['chat']['id'];

try {
    // Пытаемся установить соединение с базой данных
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    // При ошибке подключения уведомляем пользователя и логируем детали
    $userLang = $msg['from']['language_code'] ?? 'ru';
    $text     = $userLang === 'en'
        ? 'Database connection error. Please try again later.'
        : 'Ошибка подключения к базе данных. Попробуй позже.';
    logError('Ошибка подключения к БД: ' . $e->getMessage());
    send($text, $chatId);
    exit;
}

// Обрабатываем команду /start, допускающую передачу дополнительного параметра
if (isset($msg['text']) && strncmp($msg['text'], '/start', 6) === 0) {
    /**
     * Параметр, переданный вместе с командой /start.
     * Если пользователь не указал аргумент, будет возвращена пустая строка.
     *
     * @var string $startParam
     */
    $startParam = substr($msg['text'], 7);
    // Здесь при необходимости можно обработать $startParam (например, идентификатор реферала)

    $kb = [
        'keyboard' => [[[
            'text' => '🧮 Calculator',
            'web_app' => ['url' => WEBAPP_URL]
        ]]],
        'resize_keyboard' => true
    ];
    // Пытаемся отправить приветственное сообщение пользователю
    $sent = send('Нажми кнопку, заполни форму и получи расчёт', $chatId, ['reply_markup' => json_encode($kb)]);
    if (!$sent) {
        // Если отправка не удалась, уведомляем пользователя отдельным сообщением
        send('Не удалось отправить сообщение. Попробуй позже.', $chatId);
    }
    exit;
}

if (isset($msg['web_app_data']['data'])) {
    // Декодируем данные web_app и проверяем корректность JSON
    $raw = $msg['web_app_data']['data'];
    $d   = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
        // При некорректном JSON фиксируем ошибку и прекращаем обработку
        logError('Некорректный JSON в web_app_data: ' . $raw);
        $userLang = $msg['from']['language_code'] ?? 'ru';
        $text     = $userLang === 'en' ? 'Error in data. Try again.' : 'Ошибка в данных. Попробуй ещё раз.';
        if (!send($text, $chatId)) {
            $fallback = $userLang === 'en'
                ? 'Failed to send message. Try again later.'
                : 'Не удалось отправить сообщение. Попробуй позже.';
            send($fallback, $chatId);
        }
        exit;
    }
    $lang = $d['lang'] ?? 'ru';

    // Проверяем структуру и типы данных
    if (!isValidWebAppData($d)) {
        logError('Невалидные web_app_data: ' . json_encode($msg['web_app_data'], JSON_UNESCAPED_UNICODE));
        $text = $lang === 'en' ? 'Error in data. Try again.' : 'Ошибка в данных. Попробуй ещё раз.';
        // Уведомляем пользователя о неверных данных и проверяем успешность отправки
        if (!send($text, $chatId)) {
            $fallback = $lang === 'en'
                ? 'Failed to send message. Try again later.'
                : 'Не удалось отправить сообщение. Попробуй позже.';
            send($fallback, $chatId);
        }
        exit;
    }

    try {
        // Приводим строку узора к стандартному виду:
        // 1. убираем все пробелы, чтобы пользователь мог вводить
        //    значения через пробел по привычке;
        // 2. заменяем запятые в дробной части на точки, так как
        //    далее в расчётах используются числа с точкой;
        // 3. элементы узора должны быть разделены точкой с запятой,
        //    чтобы разделитель не конфликтовал с десятичной запятой.
        $patternStr = str_replace(' ', '', str_replace(',', '.', $d['pattern']));

        /**
         * Массив диаметров бусин, заданный пользователем.
         * Каждое значение приводится к float для дальнейших вычислений.
         *
         * @var float[] $pattern
         */
        $pattern = array_map('floatval', explode(';', $patternStr));

        // Формируем текстовый результат расчёта браслета на основании
        // введённых параметров и преобразованного узора
        $text = braceletText(
            (float)$d['wrist_cm'],
            (int)  $d['wraps'],
            $pattern,
            (float)$d['magnet_mm'],
            (float)$d['tolerance_mm'],
            $lang
        );

        $stmt = $pdo->prepare('INSERT INTO log'
            . ' (tg_user_id,wrist_cm,wraps,pattern,magnet_mm,tolerance_mm,result_text)'
            . ' VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $msg['from']['id'], $d['wrist_cm'], $d['wraps'],
            $d['pattern'], $d['magnet_mm'], $d['tolerance_mm'], $text
        ]);
    } catch (\Throwable $e) {
        // Логируем детали исключения и отправляем понятное сообщение пользователю
        logError('Ошибка при выполнении SQL: ' . $e->getMessage());
        $text = $lang === 'en' ? 'Error in data. Try again.' : 'Ошибка в данных. Попробуй ещё раз.';
    }
    // Отправляем результат пользователю и уведомляем о сбое при необходимости
    if (!send($text, $chatId)) {
        $fallback = $lang === 'en'
            ? 'Failed to send message. Try again later.'
            : 'Не удалось отправить сообщение. Попробуй позже.';
        send($fallback, $chatId);
    }
}
endif;

/**
 * Отправляет сообщение пользователю Telegram через метод sendMessage.
 *
 * Использует HTTP POST-запрос к Bot API. После получения ответа и
 * проверки HTTP-кода функция декодирует JSON и анализирует флаг `ok`.
 * При сетевых сбоях, отрицательном HTTP-статусе, некорректном JSON или
 * `ok = false` функция фиксирует событие в логе и возвращает `false`,
 * исключения не выбрасывает.
 *
 * Возможные причины возврата `false`:
 * - ошибка cURL (например, таймаут соединения);
 * - HTTP-код вне диапазона 2xx;
 * - некорректный JSON в ответе Bot API;
 * - `ok` отсутствует или имеет значение `false` (сообщение берётся из
 *   поля `description`).
 *
 * @param string     $text  Текст отправляемого сообщения.
 * @param int|string $chat  Идентификатор чата или имя пользователя.
 * @param array      $extra Дополнительные поля запроса,
 *                          например `reply_markup`.
 *
 * @internal Используются таймауты `CURLOPT_CONNECTTIMEOUT` = 5 и
 *           `CURLOPT_TIMEOUT` = 10.
 *
 * @return bool `true` в случае успешной отправки, иначе `false`.
 */
function send($text, $chat, $extra = []) {
    $url  = API_URL . 'sendMessage';
    $data = array_merge(['chat_id' => $chat, 'text' => $text], $extra);

    // Инициализируем cURL для отправки POST-запроса
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,                     // Используем метод POST
        CURLOPT_POSTFIELDS     => http_build_query($data),  // Тело запроса в формате key=value
        CURLOPT_RETURNTRANSFER => true,                     // Возвращаем ответ как строку
        CURLOPT_CONNECTTIMEOUT => 5,                        // Ждём соединение не более 5 секунд
        CURLOPT_TIMEOUT        => 10,                       // Общее ожидание ответа — до 10 секунд
    ]);

    $response = curl_exec($ch);

    // Проверяем наличие ошибок на уровне cURL
    if ($response === false) {
        // Логируем обнаруженную на уровне cURL ошибку и прекращаем отправку
        logError('Ошибка cURL: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Анализируем HTTP-статус ответа
    if ($httpCode < 200 || $httpCode >= 300) {
        // Фиксируем в логах некорректный HTTP-статус и тело ответа
        logError('Ошибка HTTP: статус ' . $httpCode . '; ответ: ' . $response);
        return false;
    }

    // Декодируем JSON-ответ Telegram
    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // Логируем некорректный JSON и прекращаем выполнение
        logError('Некорректный JSON в ответе API: ' . $response);
        return false;
    }

    // Проверяем наличие и значение флага `ok`
    if (!isset($decoded['ok']) || $decoded['ok'] === false) {
        // Сохраняем описание ошибки от Telegram, если оно присутствует
        $desc = $decoded['description'] ?? 'неизвестная ошибка';
        logError('Ошибка API Telegram: ' . $desc);
        return false;
    }

    return true; // Всё прошло успешно
}

/**
 * Проверяет структуру и корректность данных, поступивших из web_app.
 *
 * В процессе проверки выполняется:
 * - наличие всех обязательных полей;
 * - соответствие типов значений ожиданиям;
 * - контроль диапазонов числовых параметров
 *   (например, обхват запястья < 100 см);
 * - ограничение длины строки с паттерном и числа элементов
 *   после `explode`.
 *
 * @param mixed $d Данные из web_app_data.
 *
 * @return bool true, если данные валидны.
 */
function isValidWebAppData($d): bool {
    if (!is_array($d)) {
        return false;
    }

    $required = ['wrist_cm', 'wraps', 'pattern', 'magnet_mm', 'tolerance_mm'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $d)) {
            return false;
        }
    }

    if (!is_numeric($d['wrist_cm']) || !is_numeric($d['magnet_mm']) || !is_numeric($d['tolerance_mm'])) {
        return false;
    }

    if (!is_numeric($d['wraps'])) {
        return false;
    }

    // Ограничения для числовых значений: все значения должны быть
    // положительными и находиться в разумных пределах.
    $wrist     = (float)$d['wrist_cm'];
    $magnet    = (float)$d['magnet_mm'];
    $tolerance = (float)$d['tolerance_mm'];
    $wraps     = (int)$d['wraps'];

    if ($wrist <= 0 || $wrist >= 100) {
        return false; // обхват должен быть в диапазоне (0, 100)
    }
    if ($magnet <= 0 || $magnet >= 100) {
        return false; // размеры магнита измеряются в мм, ограничим 0..100
    }
    if ($tolerance <= 0 || $tolerance >= 100) {
        return false; // допуск по длине также ограничен
    }
    if ($wraps <= 0 || $wraps > 10) {
        return false; // число витков должно быть положительным и не слишком большим
    }

    if (!is_string($d['pattern']) || $d['pattern'] === '') {
        return false;
    }
    // Ограничиваем длину строки паттерна
    if (mb_strlen($d['pattern']) > 100) {
        return false;
    }

    // Удаляем пробелы и приводим запятые в дробной части к точкам,
    // после чего делим строку по точкам с запятой на отдельные элементы узора
    $patternStr = str_replace(' ', '', str_replace(',', '.', $d['pattern']));
    $parts      = array_map('trim', explode(';', $patternStr));
    if (empty($parts) || count($parts) > 20) {
        return false;
    }

    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            return false;
        }
        $val = (float)$p;
        if ($val <= 0 || $val >= 100) {
            return false; // каждый размер бусины в мм должен быть в пределах
        }
    }

    if (isset($d['lang']) && !is_string($d['lang'])) {
        return false;
    }

    return true;
}

