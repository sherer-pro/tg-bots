<?php

declare(strict_types=1);

namespace {
    // При прямом запуске скрипта обеспечиваем наличие автозагрузчика Composer.
    if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
}

namespace Bracelet {
    use PDO;
    use PDOException;
    use Throwable;

    /**
     * Запускает обработку входящего HTTP-запроса Telegram.
     *
     * Функция выступает "оркестратором": выполняет загрузку конфигурации,
     * подготовку зависимостей, пошаговый сценарий и отправку ответа
     * пользователю. Выделение логики в функцию позволяет подключать файл
     * через автозагрузку без немедленного выполнения.
     *
     * @return void
     */
    function runWebhook(): void
    {
        try {
            // Подключаем конфигурацию, где задаются переменные окружения.
            require __DIR__ . '/config.php';
        } catch (Throwable $e) {
            // Фиксируем ошибку конфигурации и пробрасываем исключение,
            // чтобы тесты могли её перехватить.
            logError('Ошибка конфигурации: ' . $e->getMessage());
            throw $e;
        }

        // Определяем, доверять ли заголовку X-Forwarded-For.
        $trustForwarded = filter_var(
            $_ENV['TRUST_FORWARDED'] ?? getenv('TRUST_FORWARDED'),
            FILTER_VALIDATE_BOOLEAN
        );

        // Создаём необходимые объекты для обработки запроса.
        $requestHandler = new RequestHandler($trustForwarded);
        $telegram       = new TelegramApi();

        // Читаем и валидируем входящий запрос.
        $req      = $requestHandler->handle();
        $msg      = $req['message'];
        $chatId   = $req['chatId'];
        $userId   = $req['userId'];
        $userLang = $req['userLang'];

        // Устанавливаем соединение с базой данных.
        try {
            /** @var PDO $pdo Подключение к базе данных */
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $text = $userLang === 'en'
                ? 'Database connection error. Please try again later.'
                : 'Ошибка подключения к базе данных. Попробуй позже.';
            logError('Ошибка подключения к БД: ' . $e->getMessage());
            if (!$telegram->send($text, $chatId)) {
                logError('Предупреждение: не удалось отправить уведомление об ошибке подключения, чат ' . $chatId);
            }
            return;
        }

        $storage = new StateStorage($pdo);

        // Обрабатываем команду /start.
        if (isset($msg['text']) && preg_match('/^\/start(?:\s|$)/', $msg['text'])) {
            try {
                $storage->initState($userId); // Создаём состояние первого шага.
            } catch (PDOException $e) {
                logError('Ошибка при инициализации состояния: ' . $e->getMessage());
                $text = $userLang === 'en'
                    ? 'Server error. Try again later.'
                    : 'Ошибка сервера. Попробуй позже.';
                if (!$telegram->send($text, $chatId)) {
                    logError('Предупреждение: не удалось отправить сообщение об ошибке инициализации, чат ' . $chatId);
                }
                return;
            }
            // Первый шаг диалога — запрашиваем обхват запястья.
            $text = $userLang === 'en'
                ? 'Step 1 of 5. Enter wrist circumference in centimeters.'
                : 'Шаг 1 из 5. Введи обхват запястья в сантиметрах.';
            // Логируем начало сценария.
            logInfo('Начало сценария: пользователь ' . $userId . ', язык ' . $userLang . ', чат ' . $chatId);
            if (!$telegram->send($text, $chatId)) {
                logError('Предупреждение: не удалось отправить стартовое сообщение, чат ' . $chatId);
            }
            return;
        }

        // Получаем текущее состояние пользователя.
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
            return;
        }

        if ($state === null) {
            // Диалог не начат — предлагаем отправить /start.
            $text = $userLang === 'en'
                ? 'Send /start to begin.'
                : 'Отправь /start, чтобы начать.';
            if (!$telegram->send($text, $chatId)) {
                logError('Предупреждение: не удалось отправить подсказку о /start, чат ' . $chatId);
            }
            return;
        }

        $data = $state['data'];
        $step = $state['step'];

        // Обрабатываем только текстовые сообщения.
        if (!isset($msg['text'])) {
            $text = $userLang === 'en'
                ? 'Please send a text value.'
                : 'Пожалуйста, введи значение текстом.';
            if (!$telegram->send($text, $chatId)) {
                logError('Предупреждение: не удалось отправить сообщение о необходимости текстового ввода, чат ' . $chatId);
            }
            return;
        }

        $input = trim($msg['text']);

        // Выполняем текущий шаг сценария.
        $result = processStep($step, $input, $data, $userLang);
        $next        = $result['next'];
        $responseText = $result['text'];
        $data        = $result['data'];

        if ($next === 0) {
            if ($data !== [] && $step === 5) {
                // Пользователь успешно прошёл все шаги — сохраняем результат.
                try {
                    $storage->saveResult($userId, $data, $responseText);
                } catch (PDOException $e) {
                    logError('Ошибка при сохранении результата: ' . $e->getMessage());
                }
            } else {
                // Некорректный шаг — очищаем состояние.
                $storage->clearState($userId);
                logError('Неизвестный шаг диалога: ' . $step);
            }
        } else {
            // Сохраняем состояние и переходим к следующему шагу.
            $storage->saveState($userId, $next, $data);
        }

        // Отправляем пользователю ответ.
        if (!$telegram->send($responseText, $chatId)) {
            logError('Предупреждение: не удалось отправить ответ, чат ' . $chatId);
        }
    }

    // Если файл запущен напрямую (например, через CLI), запускаем обработку.
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        runWebhook();
    }
}

