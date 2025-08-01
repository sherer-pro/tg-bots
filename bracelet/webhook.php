<?php

declare(strict_types=1);
require 'config.php';
require 'calc.php';
// Функции проверки IP-адресов Telegram вынесены в отдельный файл,
// чтобы их можно было переиспользовать и тестировать изолированно.
require __DIR__ . '/telegram_ip.php';

if (!defined('WEBHOOK_LIB')):

// Получаем заголовки и IP-адрес отправителя
$headers  = function_exists('getallheaders') ? getallheaders() : [];
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

// Секретный токен, заданный в переменных окружения
$secret = $_ENV['WEBHOOK_SECRET'] ?? getenv('WEBHOOK_SECRET') ?: '';
$tokenValid = $secret !== ''
    && isset($headers['X-Telegram-Bot-Api-Secret-Token'])
    && hash_equals($secret, $headers['X-Telegram-Bot-Api-Secret-Token']);

// Проверяем, что запрос пришёл от Telegram (по токену или IP)
if (!$tokenValid && !isTelegramIP($remoteIp)) {
    // Записываем информацию о недопустимом запросе в лог
    logError('Недопустимый запрос: IP ' . $remoteIp . ', токен: ' . ($headers['X-Telegram-Bot-Api-Secret-Token'] ?? ''));
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

if (isset($msg['text']) && $msg['text'] === '/start') {
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
        $text = braceletText(
            (float)$d['wrist_cm'],
            (int)  $d['wraps'],
            array_map('floatval', explode(',', $d['pattern'])),
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

 * Использует HTTP POST-запрос к Bot API. При сетевых сбоях или
 * отрицательном HTTP-статусе функция записывает ошибку в лог и
 * возвращает `false`, исключения не выбрасывает.
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

    return true;
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
    // Ограничиваем длину строки паттерна и число элементов
    if (mb_strlen($d['pattern']) > 100) {
        return false;
    }

    $parts = array_map('trim', explode(',', $d['pattern']));
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

/**
 * Записывает сообщение об ошибке в файл логов.
 *
 * Каждая запись дополняется временной меткой в формате ISO 8601,
 * что облегчает анализ последовательности событий.
 *
 * @param string $message Текст ошибки.
 *
 * @return void
 */
function logError(string $message): void {
    $dir = dirname(LOG_FILE); // Каталог, где хранится лог
    if (!is_dir($dir)) { // Проверяем существование каталога
        mkdir($dir, 0777, true); // Создаём каталог рекурсивно при необходимости
    }
    $time = date('c'); // Текущее время в удобочитаемом формате
    // Записываем сообщение в лог-файл
    error_log("[$time] $message\n", 3, LOG_FILE);
}
