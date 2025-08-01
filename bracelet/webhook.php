<?php
require 'config.php';
require 'calc.php';

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
    $d = json_decode($msg['web_app_data']['data'], true);
    $lang = $d['lang'] ?? 'ru';
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

function send($text, $chat, $extra = []) {
    $url = API_URL . 'sendMessage';
    $data = array_merge(['chat_id'=>$chat, 'text'=>$text], $extra);
    file_get_contents($url . '?' . http_build_query($data));
}
