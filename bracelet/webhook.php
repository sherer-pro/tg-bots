<?php

declare(strict_types=1);
require_once __DIR__ . '/logger.php'; // Функции логирования доступны сразу
require_once 'calc.php'; // Подключаем функции расчёта браслета
// Функции проверки IP-адресов Telegram вынесены в отдельный файл,
// чтобы их можно было переиспользовать и тестировать изолированно.
require_once __DIR__ . '/telegram_ip.php'; // Подключаем функции проверки IP

/**
 * Определяет IP-адрес клиента, учитывая возможность работы за прокси.
 *
 * ВАЖНО: функция предполагает, что ключи массива заголовков уже приведены
 * вызывающим кодом к нижнему регистру. Это исключает зависимость от особенностей
 * веб‑сервера и позволяет обращаться к заголовкам без учёта регистра. При
 * наличии доверенного прокси можно использовать заголовок `X-Forwarded-For`,
 * который содержит цепочку IP‑адресов. Первый элемент этого списка соответствует
 * исходному отправителю запроса. Если полученный IP некорректен или прокси не
 * доверен, следует опираться только на `REMOTE_ADDR`.
 *
 * @param array<string,string> $headers        Заголовки входящего HTTP-запроса
 *        с именами, приведёнными к нижнему регистру.
 * @param array<string,mixed>  $server         Данные о запросе, обычно `$_SERVER`.
 * @param bool                 $trustForwarded Признак доверия к заголовку
 *        `X-Forwarded-For`. При `false` значение заголовка игнорируется.
 *
 * @return string IP-адрес клиента. Пустая строка возвращается, если ни один
 *                из источников не содержит валидного адреса.
*/
function resolveRemoteIp(array $headers, array $server, bool $trustForwarded = false): string
{
    // Используем IP из X-Forwarded-For только при доверенном прокси
    if ($trustForwarded && !empty($headers['x-forwarded-for'])) {
        $forwarded = explode(',', $headers['x-forwarded-for']);
        $candidate = trim($forwarded[0]); // Первый IP из цепочки

        // Проверяем корректность IP-адреса из заголовка
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate; // Возвращаем валидный адрес
        }
        // Если IP некорректен, игнорируем заголовок и используем REMOTE_ADDR
    }

    // Возвращаем REMOTE_ADDR, если заголовок отсутствует, прокси не доверен или IP некорректен
    return $server['REMOTE_ADDR'] ?? '';
}

if (!defined('WEBHOOK_LIB')):

try {
    // Подключаем конфигурацию, где задаются переменные окружения
    require 'config.php';
} catch (Throwable $e) {
    // Если конфигурацию загрузить не удалось,
    // фиксируем ошибку и пробрасываем исключение дальше,
    // чтобы тесты или другие скрипты могли обработать её самостоятельно
    logError('Ошибка конфигурации: ' . $e->getMessage());
    throw $e;
}

// Получаем заголовки и определяем IP-адрес отправителя
$headers = function_exists('getallheaders') ? getallheaders() : [];

// Нормализуем имена заголовков один раз на раннем этапе. Далее весь код,
// включая resolveRemoteIp и проверку токена, работает с уже приведёнными к
// нижнему регистру ключами.
$headers = array_change_key_case($headers, CASE_LOWER);

/**
 * Флаг доверия к заголовку `X-Forwarded-For`.
 * Его можно задать через переменную окружения `TRUST_FORWARDED`, установив
 * значение `true` или `1`. По умолчанию заголовку не доверяем, так как он
 * может быть подделан, если запрос проходит через непроверенный прокси.
 *
 * @var bool $trustForwarded
 */
$trustForwarded = filter_var(
    $_ENV['TRUST_FORWARDED'] ?? getenv('TRUST_FORWARDED'),
    FILTER_VALIDATE_BOOLEAN
);

$remoteIp = resolveRemoteIp($headers, $_SERVER, $trustForwarded); // передаём уже нормализованные заголовки

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
    // Записываем информацию о недопустимом запросе, в лог пишется только частично скрытый токен
    /** @var string $token Токен из заголовка запроса */
    $token = $headers['x-telegram-bot-api-secret-token'] ?? '';
    /** @var string $maskedToken Токен с показанными первыми четырьмя символами */
    $maskedToken = $token !== '' ? substr($token, 0, 4) . '***' : '';
    logError('Недопустимый запрос: IP ' . $remoteIp . ', токен: ' . $maskedToken);
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
$userId = $msg['from']['id'];
$userLang = $msg['from']['language_code'] ?? 'ru';

// Устанавливаем соединение с базой данных, так как оно потребуется
// как для обработки команды /start, так и для дальнейших шагов диалога.
try {
    /** @var PDO $pdo Подключение к базе данных */
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    // При ошибке подключения уведомляем пользователя и логируем детали
    $text = $userLang === 'en'
        ? 'Database connection error. Please try again later.'
        : 'Ошибка подключения к базе данных. Попробуй позже.';
    logError('Ошибка подключения к БД: ' . $e->getMessage());
    send($text, $chatId);
    exit;
}

// Обрабатываем команду /start
if (isset($msg['text']) && preg_match('/^\/start(?:\s|$)/', $msg['text'])) {
    try {
        // Очищаем возможное предыдущее состояние пользователя и создаём новое
        $pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        $pdo->prepare('INSERT INTO user_state (tg_user_id, step, data) VALUES (?,1,?::jsonb)')
            ->execute([$userId, json_encode([], JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) {
        // Логируем ошибку и уведомляем пользователя о проблеме на сервере
        logError('Ошибка при инициализации состояния: ' . $e->getMessage());
        $text = $userLang === 'en'
            ? 'Server error. Try again later.'
            : 'Ошибка сервера. Попробуй позже.';
        send($text, $chatId);
        exit;
    }
    $text = $userLang === 'en'
        ? 'Enter wrist circumference in centimeters.'
        : 'Введи обхват запястья в сантиметрах.';
    send($text, $chatId);
    exit;
}

// Пытаемся получить текущее состояние пользователя
try {
    $stmt = $pdo->prepare('SELECT step, data FROM user_state WHERE tg_user_id = ?');
    $stmt->execute([$userId]);
    /** @var array{step:int,data:string}|false $state */
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError('Ошибка чтения состояния: ' . $e->getMessage());
    $text = $userLang === 'en'
        ? 'Server error. Try again later.'
        : 'Ошибка сервера. Попробуй позже.';
    send($text, $chatId);
    exit;
}

if ($state === false) {
    // Нет активного диалога — предлагаем начать заново
    $text = $userLang === 'en'
        ? 'Send /start to begin.'
        : 'Отправь /start, чтобы начать.';
    send($text, $chatId);
    exit;
}

$data = json_decode($state['data'], true) ?: [];
$step = (int)$state['step'];

// Обрабатываем только текстовые сообщения, так как от пользователя
// ожидаются числовые значения или строки с узором
if (!isset($msg['text'])) {
    $text = $userLang === 'en'
        ? 'Please send a text value.'
        : 'Пожалуйста, введи значение текстом.';
    send($text, $chatId);
    exit;
}

$input = trim($msg['text']);

switch ($step) {
    case 1:
        // Шаг 1 — обхват запястья в сантиметрах
        $val = str_replace(',', '.', $input);
        if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
            $text = $userLang === 'en'
                ? 'Invalid value. Enter wrist circumference in centimeters.'
                : 'Некорректный обхват. Введи число в сантиметрах.';
            send($text, $chatId);
            break;
        }
        $data['wrist_cm'] = $v;
        saveState($pdo, $userId, 2, $data);
        $text = $userLang === 'en'
            ? 'How many wraps will the bracelet have?'
            : 'Сколько будет витков?';
        send($text, $chatId);
        break;
    case 2:
        // Шаг 2 — количество витков
        if (!ctype_digit($input) || ($v = (int)$input) <= 0 || $v > 10) {
            $text = $userLang === 'en'
                ? 'Invalid wraps count. Enter a positive integer not greater than 10.'
                : 'Некорректное число витков. Введи положительное целое число не больше 10.';
            send($text, $chatId);
            break;
        }
        $data['wraps'] = $v;
        saveState($pdo, $userId, 3, $data);
        $text = $userLang === 'en'
            ? 'Enter bead pattern in millimeters separated by semicolons (e.g., 10;8).'
            : 'Введи узор: размеры бусин в мм через точку с запятой (например 10;8).';
        send($text, $chatId);
        break;
    case 3:
        // Шаг 3 — узор браслета
        $patternStr = str_replace(' ', '', str_replace(',', '.', $input));
        $parts = array_filter(array_map('trim', explode(';', $patternStr)), 'strlen');
        if (empty($parts) || count($parts) > 20) {
            $text = $userLang === 'en' ? 'Invalid pattern.' : 'Некорректный узор.';
            send($text, $chatId);
            break;
        }
        $valid = true;
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                $valid = false;
                break;
            }
            $pv = (float)$p;
            if ($pv <= 0 || $pv >= 100) {
                $valid = false;
                break;
            }
        }
        if (!$valid) {
            $text = $userLang === 'en' ? 'Invalid pattern.' : 'Некорректный узор.';
            send($text, $chatId);
            break;
        }
        $data['pattern'] = implode(';', $parts);
        saveState($pdo, $userId, 4, $data);
        $text = $userLang === 'en'
            ? 'Enter magnet size in millimeters.'
            : 'Укажи размер магнита в миллиметрах.';
        send($text, $chatId);
        break;
    case 4:
        // Шаг 4 — размер магнита
        $val = str_replace(',', '.', $input);
        if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
            $text = $userLang === 'en'
                ? 'Invalid magnet size.'
                : 'Некорректный размер магнита.';
            send($text, $chatId);
            break;
        }
        $data['magnet_mm'] = $v;
        saveState($pdo, $userId, 5, $data);
        $text = $userLang === 'en'
            ? 'Enter allowable length tolerance in millimeters.'
            : 'Введи допуск по длине в миллиметрах.';
        send($text, $chatId);
        break;
    case 5:
        // Шаг 5 — допуск по длине и финальный расчёт
        $val = str_replace(',', '.', $input);
        if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
            $text = $userLang === 'en'
                ? 'Invalid tolerance.'
                : 'Некорректный допуск.';
            send($text, $chatId);
            break;
        }
        $data['tolerance_mm'] = $v;
        $pattern = array_map('floatval', explode(';', $data['pattern']));
        $text = braceletText(
            (float)$data['wrist_cm'],
            (int)$data['wraps'],
            $pattern,
            (float)$data['magnet_mm'],
            (float)$data['tolerance_mm'],
            $userLang
        );
        try {
            $stmt = $pdo->prepare('INSERT INTO log (tg_user_id,wrist_cm,wraps,pattern,magnet_mm,tolerance_mm,result_text) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([
                $userId,
                $data['wrist_cm'],
                $data['wraps'],
                $data['pattern'],
                $data['magnet_mm'],
                $data['tolerance_mm'],
                $text
            ]);
            $pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        } catch (PDOException $e) {
            logError('Ошибка при сохранении результата: ' . $e->getMessage());
        }
        send($text, $chatId);
        break;
    default:
        // Некорректный шаг — сбрасываем состояние
        $pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        $text = $userLang === 'en'
            ? 'Send /start to begin.'
            : 'Отправь /start, чтобы начать.';
        send($text, $chatId);
}

endif;

/**
 * Отправляет сообщение пользователю.
 *
 * @param string      $text  Текст сообщения.
 * @param int|string  $chat  ID чата или @username.
 * @param array       $extra Дополнительные параметры.
 *
 * @return bool true при успешной отправке.
 */
function send(string $text, int|string $chat, array $extra = []): bool {
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
 * Сохраняет состояние диалога пользователя в таблице `user_state`.
 *
 * @param PDO   $pdo     Подключение к базе данных.
 * @param int   $userId  Идентификатор пользователя Telegram.
 * @param int   $step    Текущий шаг сценария.
 * @param array $data    Накопленные параметры пользователя.
 *
 * @return void
 */
function saveState(PDO $pdo, int $userId, int $step, array $data): void {
    $stmt = $pdo->prepare('UPDATE user_state SET step = ?, data = ?, updated_at = now() WHERE tg_user_id = ?');
    $stmt->execute([$step, json_encode($data, JSON_UNESCAPED_UNICODE), $userId]);
}
