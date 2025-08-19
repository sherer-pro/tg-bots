<?php

namespace Bracelet {
    /**
     * Заглушка для функции file_get_contents внутри пространства имён Bracelet.
     * Она позволяет тестам задавать содержимое входного потока `php://input`.
     *
     * @param string $filename Имя файла, запрос к которому производится.
     * @return string           Содержимое, предоставленное тестом, либо данные реального файла.
     */
    function file_get_contents(string $filename): string
    {
        // Для всех файлов, кроме php://input, используем стандартную реализацию.
        if ($filename !== 'php://input') {
            return \file_get_contents($filename);
        }
        // Возвращаем заранее подготовленное тело запроса.
        return \RequestHandlerExceptionTest::$body;
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Bracelet\RequestHandler;
    use Bracelet\Request;

    /**
     * Набор тестов, проверяющих обработку запросов классом RequestHandler.
     */
    final class RequestHandlerExceptionTest extends TestCase
    {
        /**
         * Заголовки, возвращаемые фиктивной функцией getallheaders().
         *
         * @var array<string,string>
         */
        public static array $headers = [];

        /**
         * Содержимое входящего тела запроса для заглушки `file_get_contents`.
         */
        public static string $body = '';

        /**
         * Переопределяем функцию getallheaders для CLI окружения.
         * Функция должна существовать до загрузки RequestHandler.
         *
         * @return array<string,string> Список тестовых заголовков.
         */
        public static function getAllHeadersStub(): array
        {
            return self::$headers;
        }

        public static function setUpBeforeClass(): void
        {
            // Определяем глобальную функцию getallheaders, если её нет.
            if (!function_exists('getallheaders')) {
                function getallheaders() {
                    return RequestHandlerExceptionTest::getAllHeadersStub();
                }
            }
        }

        protected function setUp(): void
        {
            $_SERVER = [];
            $_ENV['WEBHOOK_SECRET'] = '';
            self::$headers = [];
            self::$body = '';
        }

        /**
         * При обращении с IP вне диапазона Telegram выбрасывается InvalidIpException.
         */
        public function testInvalidIpThrowsException(): void
        {
            $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
            $handler = new RequestHandler();
            $this->expectException(\Bracelet\InvalidIpException::class);
            $handler->handle();
        }

        /**
         * Некорректный токен приводит к InvalidTokenException.
         */
        public function testInvalidTokenThrowsException(): void
        {
            $_SERVER['REMOTE_ADDR'] = '149.154.160.1'; // допустимый IP Telegram
            $_ENV['WEBHOOK_SECRET'] = 'secret';
            self::$headers = ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'];

            $handler = new RequestHandler();
            $this->expectException(\Bracelet\InvalidTokenException::class);
            $handler->handle();
        }

        /**
         * Превышение размера тела по заголовку вызывает OversizedBodyException.
         */
        public function testOversizedBodyHeaderThrowsException(): void
        {
            $_SERVER['REMOTE_ADDR'] = '149.154.160.1';
            $_SERVER['CONTENT_LENGTH'] = '2048';

            // Устанавливаем очень маленький лимит, чтобы сработала проверка.
            $handler = new RequestHandler(false, 1024);
            $this->expectException(\Bracelet\OversizedBodyException::class);
            $handler->handle();
        }

        /**
         * Пустое тело запроса приводит к BadRequestException.
         */
        public function testEmptyBodyThrowsException(): void
        {
            $_SERVER['REMOTE_ADDR'] = '149.154.160.1';
            $handler = new RequestHandler();
            $this->expectException(\Bracelet\BadRequestException::class);
            $handler->handle();
        }

        /**
         * Успешный разбор запроса заполняет DTO корректными данными.
         */
        public function testHandleReturnsFilledRequest(): void
        {
            $_SERVER['REMOTE_ADDR'] = '149.154.160.1';

            // Формируем минимальное обновление Telegram.
            // Идентификаторы задаём строками, чтобы проверить приведение к int.
            $update = [
                'message' => [
                    'chat' => ['id' => '1'],
                    'from' => ['id' => '42', 'language_code' => 'ru'],
                    'text' => 'привет',
                ],
            ];
            self::$body = json_encode($update, JSON_UNESCAPED_UNICODE);

            $handler = new RequestHandler();
            $request = $handler->handle();

            $this->assertInstanceOf(Request::class, $request);
            $this->assertSame($update['message'], $request->message);
            $this->assertSame(1, $request->chatId);
            $this->assertSame(42, $request->userId);
            $this->assertSame('ru', $request->userLang);
        }
    }
}

