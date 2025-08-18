<?php

declare(strict_types=1);
require_once __DIR__ . '/logger.php'; // Функции логирования доступны сразу
require_once __DIR__ . '/calc.php'; // Подключаем функции расчёта браслета
require_once __DIR__ . '/scenario.php'; // Подключаем логику пошагового сценария
// Общие сетевые инструменты, включая resolveRemoteIp, вынесены в отдельный модуль,
// чтобы их можно было переиспользовать и тестировать изолированно.
require_once __DIR__ . '/network.php'; // Функции работы с сетью
// Функции проверки IP-адресов Telegram отделены в модуль telegram_ip.php,
// чтобы не смешивать логику проверки списков адресов с определением IP клиента.
require_once __DIR__ . '/telegram_ip.php'; // Подключаем функции проверки IP Telegram

// Основной сценарий обработки входящих запросов Telegram. Теперь файл
// предназначен исключительно для непосредственного запуска вебхука и не
// предполагается его подключение как библиотеки.

try {
    // Подключаем конфигурацию, где задаются переменные окружения
    require __DIR__ . '/config.php';
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

// Всегда убеждаемся, что запрос пришёл с IP Telegram
if (!isTelegramIP($remoteIp)) {
    logError('Недопустимый запрос: IP ' . $remoteIp);
    http_response_code(403);
    exit;
}

/**
 * Секретный токен, заданный в переменных окружения.
 * Отсутствие значения допустимо только в тестовой среде.
 *
 * @var string $secret
 */
$secret = $_ENV['WEBHOOK_SECRET'] ?? getenv('WEBHOOK_SECRET') ?: '';

if ($secret !== '') {
    // При наличии секрета сверяем его с заголовком Telegram
    if (!isset($headers['x-telegram-bot-api-secret-token'])
        || !hash_equals($secret, $headers['x-telegram-bot-api-secret-token'])) {
        /** @var string $token Токен из заголовка запроса */
        $token = $headers['x-telegram-bot-api-secret-token'] ?? '';
        /** @var string $maskedToken Маскируем токен, показывая только первые четыре символа */
        $maskedToken = $token !== '' ? substr($token, 0, 4) . '***' : '';
        logError('Недопустимый токен: ' . $maskedToken . ', IP ' . $remoteIp);
        http_response_code(403);
        exit;
    }
}

// Максимально допустимый объём входящего тела запроса — 1 мегабайт.
// Более крупные запросы считаются подозрительными и отвергаются сразу.
$maxBodySize = 1024 * 1024; // 1 МБ в байтах

// Перед чтением `php://input` проверяем указанный клиентом размер тела.
// Заголовок `Content-Length` может отсутствовать, поэтому дополнительно
// анализируем переменную `$_SERVER['CONTENT_LENGTH']`.
$contentLengthHeader = (int) ($headers['content-length'] ?? ($_SERVER['CONTENT_LENGTH'] ?? 0));
if ($contentLengthHeader > $maxBodySize) {
    // Сообщаем в лог, что запрос превысил лимит, и прекращаем обработку.
    logError('Превышен допустимый размер запроса по заголовку: ' . $contentLengthHeader . ' байт');
    http_response_code(413); // HTTP 413 Payload Too Large
    exit; // Немедленно завершаем выполнение скрипта
}

// Считываем тело запроса и проверяем фактический размер полученных данных.
$body = file_get_contents('php://input');
if (strlen($body) > $maxBodySize) {
    // Если реальный размер оказался больше ожидаемого, логируем инцидент
    // и возвращаем ошибку 413. Это защищает от некорректных клиентов,
    // которые могли подделать или не указать заголовок `Content-Length`.
    logError('Превышен допустимый размер запроса: ' . strlen($body) . ' байт');
    http_response_code(413);
    exit;
}

// Пытаемся декодировать JSON только после успешного прохождения проверки размеров
$update = json_decode($body, true);
if ($update === null && json_last_error() !== JSON_ERROR_NONE) {
    // Если JSON некорректен, фиксируем это и возвращаем 400
    logError('Некорректный JSON во входящем запросе: ' . $body);
    http_response_code(400);
    exit;
}
if (!isset($update['message'])) {
    // Логируем полное обновление, если ключ message отсутствует, чтобы
    // можно было диагностировать источник некорректного запроса
    logError(
        'Некорректное обновление: поле message отсутствует. Полный JSON: '
        . json_encode($update, JSON_UNESCAPED_UNICODE)
    );
    exit;
}
$msg    = $update['message'];
$decodedMsg = json_encode($msg, JSON_UNESCAPED_UNICODE);
// Фиксируем в логе факт получения сообщения и IP отправителя
logInfo('Получено сообщение: ' . $decodedMsg . '; IP ' . $remoteIp);
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
    // Отправляем пользователю информацию об ошибке и проверяем результат
    if (!send($text, $chatId)) {
        // Логируем предупреждение, если сообщение не ушло
        logError('Предупреждение: не удалось отправить уведомление об ошибке подключения, чат ' . $chatId);
    }
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
        // Пытаемся уведомить пользователя и фиксируем результат отправки
        if (!send($text, $chatId)) {
            logError('Предупреждение: не удалось отправить сообщение об ошибке инициализации, чат ' . $chatId);
        }
        exit;
    }
    // Первый шаг диалога — запрашиваем обхват запястья
    $text = $userLang === 'en'
        ? 'Step 1 of 5. Enter wrist circumference in centimeters.'
        : 'Шаг 1 из 5. Введи обхват запястья в сантиметрах.';
    // Логируем начало сценария для текущего пользователя
    logInfo('Начало сценария: пользователь ' . $userId . ', язык ' . $userLang . ', чат ' . $chatId);
    // Отправляем приглашение к первому шагу и проверяем, что сообщение доставлено
    if (!send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить стартовое сообщение, чат ' . $chatId);
    }
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
    // Пытаемся сообщить пользователю о проблеме и фиксируем результат
    if (!send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить сообщение об ошибке чтения состояния, чат ' . $chatId);
    }
    exit;
}

if ($state === false) {
    // Нет активного диалога — предлагаем начать заново
    $text = $userLang === 'en'
        ? 'Send /start to begin.'
        : 'Отправь /start, чтобы начать.';
    // Сообщаем пользователю, что диалог не начат, и проверяем отправку
    if (!send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить подсказку о /start, чат ' . $chatId);
    }
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
    // Отправляем рекомендацию отправить текст и проверяем доставку
    if (!send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить сообщение о необходимости текстового ввода, чат ' . $chatId);
    }
    exit;
}

$input = trim($msg['text']);

// Обрабатываем текущий шаг сценария с помощью общей функции
$result = processStep($step, $input, $data, $userLang);
$next = $result['next'];          // Номер следующего шага
$responseText = $result['text'];  // Текст ответа пользователю
$data = $result['data'];          // Обновлённые данные

if ($next === 0) {
    if ($data !== [] && $step === 5) {
        // Пользователь успешно прошёл все шаги — сохраняем результат
        try {
            $stmt = $pdo->prepare('INSERT INTO log (tg_user_id,wrist_cm,wraps,pattern,magnet_mm,tolerance_mm,result_text) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([
                $userId,
                $data['wrist_cm'],
                $data['wraps'],
                $data['pattern'],
                $data['magnet_mm'],
                $data['tolerance_mm'],
                $responseText,
            ]);
            $pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        } catch (PDOException $e) {
            logError('Ошибка при сохранении результата: ' . $e->getMessage());
        }
    } else {
        // Некорректный шаг — очищаем состояние и сообщаем в лог
        $pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        logError('Неизвестный шаг диалога: ' . $step);
    }
} else {
    // Сохраняем состояние и переходим к следующему шагу
    saveState($pdo, $userId, $next, $data);
}
// Отправляем пользователю ответ и проверяем, успешно ли он доставлен
if (!send($responseText, $chatId)) {
    logError('Предупреждение: не удалось отправить ответ, чат ' . $chatId);
}

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
    // CURRENT_TIMESTAMP поддерживается как PostgreSQL, так и SQLite,
    // поэтому подходит для тестов и боевого окружения.
    $stmt = $pdo->prepare('UPDATE user_state SET step = ?, data = ?, updated_at = CURRENT_TIMESTAMP WHERE tg_user_id = ?');
    $stmt->execute([$step, json_encode($data, JSON_UNESCAPED_UNICODE), $userId]);
}
