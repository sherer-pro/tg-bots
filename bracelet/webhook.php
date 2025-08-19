<?php

declare(strict_types=1);
require_once __DIR__ . '/logger.php'; // Функции логирования доступны сразу
require_once __DIR__ . '/calc.php'; // Подключаем функции расчёта браслета
require_once __DIR__ . '/scenario.php'; // Подключаем логику пошагового сценария
require_once __DIR__ . '/network.php'; // Функции работы с сетью
require_once __DIR__ . '/telegram_ip.php'; // Подключаем функции проверки IP Telegram
require_once __DIR__ . '/RequestHandler.php'; // Чтение и валидация входящего запроса
require_once __DIR__ . '/StateStorage.php';   // Работа с таблицами user_state и log
require_once __DIR__ . '/TelegramApi.php';    // Отправка сообщений через API

// Основной сценарий обработки входящих запросов Telegram. Файл предназначен
// исключительно для запуска вебхука и выполняет лишь оркестрацию.

try {
    // Подключаем конфигурацию, где задаются переменные окружения
    require __DIR__ . '/config.php';
} catch (Throwable $e) {
    // Если конфигурацию загрузить не удалось, фиксируем ошибку и
    // пробрасываем исключение дальше для обработки тестами
    logError('Ошибка конфигурации: ' . $e->getMessage());
    throw $e;
}

// Определяем, доверять ли заголовку X-Forwarded-For
$trustForwarded = filter_var(
    $_ENV['TRUST_FORWARDED'] ?? getenv('TRUST_FORWARDED'),
    FILTER_VALIDATE_BOOLEAN
);

// Создаём необходимые объекты
$requestHandler = new RequestHandler($trustForwarded);
$telegram       = new TelegramApi();

// Читаем и валидируем входящий запрос
$req      = $requestHandler->handle();
$msg      = $req['message'];
$chatId   = $req['chatId'];
$userId   = $req['userId'];
$userLang = $req['userLang'];

// Устанавливаем соединение с базой данных
try {
    /** @var PDO $pdo Подключение к базе данных */
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    $text = $userLang === 'en'
        ? 'Database connection error. Please try again later.'
        : 'Ошибка подключения к базе данных. Попробуй позже.';
    logError('Ошибка подключения к БД: ' . $e->getMessage());
    if (!$telegram->send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить уведомление об ошибке подключения, чат ' . $chatId);
    }
    exit;
}

$storage = new StateStorage($pdo);

// Обрабатываем команду /start
if (isset($msg['text']) && preg_match('/^\/start(?:\s|$)/', $msg['text'])) {
    try {
        $storage->initState($userId); // Создаём состояние первого шага
    } catch (PDOException $e) {
        logError('Ошибка при инициализации состояния: ' . $e->getMessage());
        $text = $userLang === 'en'
            ? 'Server error. Try again later.'
            : 'Ошибка сервера. Попробуй позже.';
        if (!$telegram->send($text, $chatId)) {
            logError('Предупреждение: не удалось отправить сообщение об ошибке инициализации, чат ' . $chatId);
        }
        exit;
    }
    // Первый шаг диалога — запрашиваем обхват запястья
    $text = $userLang === 'en'
        ? 'Step 1 of 5. Enter wrist circumference in centimeters.'
        : 'Шаг 1 из 5. Введи обхват запястья в сантиметрах.';
    // Логируем начало сценария
    logInfo('Начало сценария: пользователь ' . $userId . ', язык ' . $userLang . ', чат ' . $chatId);
    if (!$telegram->send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить стартовое сообщение, чат ' . $chatId);
    }
    exit;
}

// Получаем текущее состояние пользователя
try {
    $state = $storage->getState($userId);
} catch (PDOException $e) {
    logError('Ошибка чтения состояния: ' . $e->getMessage());
    $text = $userLang === 'en'
        ? 'Server error. Try again later.'
        : 'Ошибка сервера. Попробуй позже.';
    if (!$telegram->send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить сообщение об ошибке чтения состояния, чат ' . $chatId);
    }
    exit;
}

if ($state === null) {
    // Диалог не начат — предлагаем отправить /start
    $text = $userLang === 'en'
        ? 'Send /start to begin.'
        : 'Отправь /start, чтобы начать.';
    if (!$telegram->send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить подсказку о /start, чат ' . $chatId);
    }
    exit;
}

$data = $state['data'];
$step = $state['step'];

// Обрабатываем только текстовые сообщения
if (!isset($msg['text'])) {
    $text = $userLang === 'en'
        ? 'Please send a text value.'
        : 'Пожалуйста, введи значение текстом.';
    if (!$telegram->send($text, $chatId)) {
        logError('Предупреждение: не удалось отправить сообщение о необходимости текстового ввода, чат ' . $chatId);
    }
    exit;
}

$input = trim($msg['text']);

// Выполняем текущий шаг сценария
$result = processStep($step, $input, $data, $userLang);
$next = $result['next'];          // Номер следующего шага
$responseText = $result['text'];  // Текст ответа пользователю
$data = $result['data'];          // Обновлённые данные

if ($next === 0) {
    if ($data !== [] && $step === 5) {
        // Пользователь успешно прошёл все шаги — сохраняем результат
        try {
            $storage->saveResult($userId, $data, $responseText);
        } catch (PDOException $e) {
            logError('Ошибка при сохранении результата: ' . $e->getMessage());
        }
    } else {
        // Некорректный шаг — очищаем состояние
        $storage->clearState($userId);
        logError('Неизвестный шаг диалога: ' . $step);
    }
} else {
    // Сохраняем состояние и переходим к следующему шагу
    $storage->saveState($userId, $next, $data);
}

// Отправляем пользователю ответ
if (!$telegram->send($responseText, $chatId)) {
    logError('Предупреждение: не удалось отправить ответ, чат ' . $chatId);
}
