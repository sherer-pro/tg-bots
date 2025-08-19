<?php

declare(strict_types=1);

namespace {
    // При прямом запуске скрипта обеспечиваем наличие автозагрузчика Composer.
    if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
}

namespace Bracelet {
    use PDOException;

    /**
     * Класс, инкапсулирующий работу webhook-скрипта.
     *
     * Он выполняет загрузку конфигурации, проверку входного запроса,
     * взаимодействие с хранилищем состояния и отправку ответа
     * пользователю Telegram. Основной публичный метод {@see handle()}
     * является точкой входа и последовательно выполняет все шаги.
     */
    final class WebhookProcessor
    {
        /** @var Config Объект конфигурации приложения */
        private Config $config;

        /** @var RequestHandler Объект для чтения и валидации запроса */
        private RequestHandler $requestHandler;

        /** @var TelegramApi Клиент Telegram API */
        private TelegramApi $telegram;

        /** @var StateStorage Хранилище шагов сценария */
        private StateStorage $storage;

        /** @var array Входящее сообщение Telegram */
        private array $msg = [];

        /** @var int Идентификатор чата */
        private int $chatId = 0;

        /** @var int Идентификатор пользователя */
        private int $userId = 0;

        /** @var string Язык пользователя */
        private string $userLang = 'en';

        /** @var array Текущие накопленные данные диалога */
        private array $data = [];

        /** @var int Номер текущего шага */
        private int $step = 0;

        /** @var string Последний ответ пользователю */
        private string $responseText = '';

        /**
         * @param Config $config Конфигурация приложения.
         */
        public function __construct(Config $config)
        {
            $this->config = $config;
        }

        /**
         * Точка входа обработки webhook-запроса.
         *
         * Метод последовательно выполняет загрузку конфигурации,
         * обработку команды `/start`, получение состояния,
         * вычисление следующего шага и отправку ответа.
         *
         * @return void
         */
        public function handle(): void
        {
            // Подготавливаем окружение и зависимости.
            if (!$this->loadConfig()) {
                return; // Ошибки уже залогированы внутри метода.
            }

            // Специальная обработка команды /start.
            if ($this->handleStart()) {
                return;
            }

            // Считываем состояние пользователя и валидируем сообщение.
            if (!$this->fetchState()) {
                return;
            }

            // Выполняем текущий шаг сценария и сохраняем изменения.
            $this->processMessage();

            // Отправляем итоговый ответ пользователю.
            $this->sendResponse();
        }

        /**
         * Подготавливает необходимые объекты и проверяет входной запрос.
         *
         * @return bool true в случае успеха, false при ошибке подключения к БД.
         */
        private function loadConfig(): bool
        {
            // Определяем, доверять ли заголовку X-Forwarded-For.
            $trustForwarded = filter_var(
                $_ENV['TRUST_FORWARDED'] ?? getenv('TRUST_FORWARDED'),
                FILTER_VALIDATE_BOOLEAN
            );

            // Создаём необходимые объекты для обработки запроса.
            $this->requestHandler = new RequestHandler($trustForwarded);
            $this->telegram       = new TelegramApi($this->config);

            // Читаем и валидируем входящий запрос.
            $req            = $this->requestHandler->handle();
            // Объект Request предоставляет готовые свойства вместо индексов массива.
            $this->msg      = $req->message;
            $this->chatId   = $req->chatId;
            $this->userId   = $req->userId;
            $this->userLang = $req->userLang;

            // Устанавливаем соединение с базой данных.
            try {
                $this->storage = new StateStorage($this->config);
            } catch (PDOException $e) {
                $text = $this->userLang === 'en'
                    ? 'Database connection error. Please try again later.'
                    : 'Ошибка подключения к базе данных. Попробуй позже.';
                logError('Ошибка подключения к БД: ' . $e->getMessage());
                if (!$this->telegram->send($text, $this->chatId)) {
                    logError('Предупреждение: не удалось отправить уведомление об ошибке подключения, чат ' . $this->chatId);
                }
                return false;
            }

            return true;
        }

        /**
         * Обрабатывает команду /start, если она присутствует.
         *
         * @return bool true, если команда была обработана и дальше продолжать не нужно.
         */
        private function handleStart(): bool
        {
            if (isset($this->msg['text']) && preg_match('/^\/start(?:\s|$)/', $this->msg['text'])) {
                try {
                    // Создаём состояние первого шага.
                    $this->storage->initState($this->userId);
                } catch (PDOException $e) {
                    logError('Ошибка при инициализации состояния: ' . $e->getMessage());
                    $text = $this->userLang === 'en'
                        ? 'Server error. Try again later.'
                        : 'Ошибка сервера. Попробуй позже.';
                    if (!$this->telegram->send($text, $this->chatId)) {
                        logError('Предупреждение: не удалось отправить сообщение об ошибке инициализации, чат ' . $this->chatId);
                    }
                    return true;
                }

                // Первый шаг диалога — запрашиваем обхват запястья.
                $text = $this->userLang === 'en'
                    ? 'Step 1 of 5. Enter wrist circumference in centimeters.'
                    : 'Шаг 1 из 5. Введи обхват запястья в сантиметрах.';

                // Логируем начало сценария.
                logInfo('Начало сценария: пользователь ' . $this->userId . ', язык ' . $this->userLang . ', чат ' . $this->chatId);
                if (!$this->telegram->send($text, $this->chatId)) {
                    logError('Предупреждение: не удалось отправить стартовое сообщение, чат ' . $this->chatId);
                }
                return true;
            }

            return false;
        }

        /**
         * Получает текущее состояние пользователя и проверяет входящее сообщение.
         *
         * @return bool true, если состояние получено и сообщение валидно.
         */
        private function fetchState(): bool
        {
            try {
                $state = $this->storage->getState($this->userId);
            } catch (PDOException $e) {
                logError('Ошибка чтения состояния: ' . $e->getMessage());
                $text = $this->userLang === 'en'
                    ? 'Server error. Try again later.'
                    : 'Ошибка сервера. Попробуй позже.';
                if (!$this->telegram->send($text, $this->chatId)) {
                    logError('Предупреждение: не удалось отправить сообщение об ошибке чтения состояния, чат ' . $this->chatId);
                }
                return false;
            }

            if ($state === null) {
                // Диалог не начат — предлагаем отправить /start.
                $text = $this->userLang === 'en'
                    ? 'Send /start to begin.'
                    : 'Отправь /start, чтобы начать.';
                if (!$this->telegram->send($text, $this->chatId)) {
                    logError('Предупреждение: не удалось отправить подсказку о /start, чат ' . $this->chatId);
                }
                return false;
            }

            // Обрабатываем только текстовые сообщения.
            if (!isset($this->msg['text'])) {
                $text = $this->userLang === 'en'
                    ? 'Please send a text value.'
                    : 'Пожалуйста, введи значение текстом.';
                if (!$this->telegram->send($text, $this->chatId)) {
                    logError('Предупреждение: не удалось отправить сообщение о необходимости текстового ввода, чат ' . $this->chatId);
                }
                return false;
            }

            $this->data = $state['data'];
            $this->step = $state['step'];
            $this->responseText = '';

            return true;
        }

        /**
         * Выполняет один шаг сценария и сохраняет состояние пользователя.
         *
         * @return void
         */
        private function processMessage(): void
        {
            $input  = trim($this->msg['text']);
            $result = processStep($this->step, $input, $this->data, $this->userLang);

            $next              = $result['next'];
            $this->responseText = $result['text'];
            $this->data         = $result['data'];

            if ($next === 0) {
                if ($this->data !== [] && $this->step === 5) {
                    // Пользователь успешно прошёл все шаги — сохраняем результат.
                    try {
                        $this->storage->saveResult($this->userId, $this->data, $this->responseText);
                    } catch (PDOException $e) {
                        logError('Ошибка при сохранении результата: ' . $e->getMessage());
                    }
                } else {
                    // Некорректный шаг — очищаем состояние.
                    $this->storage->clearState($this->userId);
                    logError('Неизвестный шаг диалога: ' . $this->step);
                }
            } else {
                // Сохраняем состояние и переходим к следующему шагу.
                $this->storage->saveState($this->userId, $next, $this->data);
            }
        }

        /**
         * Отправляет пользователю подготовленный ответ.
         *
         * @return void
         */
        private function sendResponse(): void
        {
            if (!$this->telegram->send($this->responseText, $this->chatId)) {
                logError('Предупреждение: не удалось отправить ответ, чат ' . $this->chatId);
            }
        }
    }

    // Если файл запущен напрямую (например, через CLI), запускаем обработку.
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        try {
            // Загружаем конфигурацию и передаём её в обработчик.
            $config = require __DIR__ . '/config.php';
            (new WebhookProcessor($config))->handle();
        } catch (InvalidIpException|InvalidTokenException $e) {
            // Запрос отклонён из-за IP или токена.
            http_response_code(403);
        } catch (OversizedBodyException $e) {
            // Размер тела превышает допустимый лимит.
            http_response_code(413);
        } catch (BadRequestException $e) {
            // Проблема в формате запроса: пустое тело, неверный JSON и т. п.
            http_response_code(400);
            echo $e->getMessage();
        }
    }
}

