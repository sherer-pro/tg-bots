<?php
require 'config.php';
require 'calc.php';

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
    error_log('Недопустимый запрос: IP ' . $remoteIp . ', токен: ' . ($headers['X-Telegram-Bot-Api-Secret-Token'] ?? '')); // логируем попытку
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
if (!isset($update['message'])) exit;
$msg    = $update['message'];
$chatId = $msg['chat']['id'];

$pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

if (isset($msg['text']) && $msg['text'] === '/start') {
    $kb = [
        'keyboard' => [[[
            'text' => '🧮 Calculator',
            'web_app' => ['url' => WEBAPP_URL]
        ]]],
        'resize_keyboard' => true
    ];
    send('Нажми кнопку, заполни форму и получи расчёт', $chatId, ['reply_markup' => json_encode($kb)]);
    exit;
}

if (isset($msg['web_app_data']['data'])) {
    $d    = json_decode($msg['web_app_data']['data'], true);
    $lang = $d['lang'] ?? 'ru';

    // Проверяем структуру и типы данных
    if (!isValidWebAppData($d)) {
        error_log('Невалидные web_app_data: ' . json_encode($msg['web_app_data'], JSON_UNESCAPED_UNICODE));
        $text = $lang === 'en' ? 'Error in data. Try again.' : 'Ошибка в данных. Попробуй ещё раз.';
        send($text, $chatId);
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

        $stmt = $pdo->prepare('INSERT INTO log
            (tg_user_id,wrist_cm,wraps,pattern,magnet_mm,tolerance_mm,result_text)
            VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $msg['from']['id'], $d['wrist_cm'], $d['wraps'],
            $d['pattern'], $d['magnet_mm'], $d['tolerance_mm'], $text
        ]);
    } catch (\Throwable $e) {
        $text = $lang === 'en' ? 'Error in data. Try again.' : 'Ошибка в данных. Попробуй ещё раз.';
    }
    send($text, $chatId);
}

/**
 * Отправляет сообщение пользователю Telegram.
 *
 * @param string     $text  Текст сообщения.
 * @param int|string $chat  Идентификатор чата.
 * @param array      $extra Дополнительные параметры запроса.
 *
 * @return void
 */
function send($text, $chat, $extra = []) {
    $url = API_URL . 'sendMessage';
    $data = array_merge(['chat_id'=>$chat, 'text'=>$text], $extra);
    file_get_contents($url . '?' . http_build_query($data));
}

/**
 * Проверяет структуру и типы данных, полученных от web_app.
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

    if (!is_string($d['pattern']) || $d['pattern'] === '') {
        return false;
    }

    $parts = array_map('trim', explode(',', $d['pattern']));
    if (empty($parts)) {
        return false;
    }

    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            return false;
        }
    }

    if (isset($d['lang']) && !is_string($d['lang'])) {
        return false;
    }

    return true;
}

/**
 * Проверяет, принадлежит ли IP-адрес диапазонам Telegram.
 *
 * @param string $ip IP-адрес клиента.
 *
 * @return bool
 */
function isTelegramIP(string $ip): bool {
    $ranges = [
        '149.154.160.0/20',
        '91.108.4.0/22',
    ];
    foreach ($ranges as $range) {
        if (ipInRange($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Проверяет, входит ли IP в указанный диапазон CIDR.
 *
 * @param string $ip   Проверяемый IP-адрес.
 * @param string $cidr Диапазон в формате CIDR.
 *
 * @return bool
 */
function ipInRange(string $ip, string $cidr): bool {
    [$subnet, $mask] = explode('/', $cidr);
    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $mask       = -1 << (32 - (int)$mask);
    $subnetLong &= $mask;
    return ($ipLong & $mask) === $subnetLong;
}
