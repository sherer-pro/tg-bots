<?php

namespace Bracelet {
    /**
     * Заглушка для функции file_get_contents внутри пространства имён Bracelet.
     *
     * Подменяет чтение `php://input` данными из потока `php://memory`,
     * что позволяет эмулировать входящие запросы без веб-сервера.
     *
     * Проверяем существование функции, чтобы избежать переопределения,
     * так как аналогичная заглушка используется в других тестах.
     */
    if (!function_exists(__NAMESPACE__ . '\\file_get_contents')) {
        /**
         * @param string $filename Имя считываемого файла.
         * @return string Содержимое входящего тела запроса.
         */
        function file_get_contents(string $filename): string
        {
            if ($filename !== 'php://input') {
                return \file_get_contents($filename);
            }
            $stream = \RequestHandlerTest::$input;
            if (!is_resource($stream)) {
                return '';
            }
            rewind($stream);
            return stream_get_contents($stream);
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Bracelet\RequestHandler;
    use Bracelet\InvalidIpException;
    use Bracelet\InvalidTokenException;
    use Bracelet\BadRequestException;
    use Bracelet\OversizedBodyException;

    /**
     * Тесты для проверки выброса исключений RequestHandler
     * и корректной установки HTTP-кодов в webhook.php.
     */
    final class RequestHandlerTest extends TestCase
    {
        /**
         * Поток, представляющий тело входящего запроса.
         *
         * @var resource|null
         */
        public static $input = null;

        /**
         * Заголовки, возвращаемые заглушкой getallheaders().
         *
         * @var array<string,string>
         */
        public static array $headers = [];

        /**
         * Заглушка функции getallheaders() для CLI окружения.
         *
         * @return array<string,string>
         */
        public static function getAllHeadersStub(): array
        {
            return self::$headers;
        }

        public static function setUpBeforeClass(): void
        {
            if (!function_exists('getallheaders')) {
                function getallheaders() {
                    return RequestHandlerTest::getAllHeadersStub();
                }
            }
        }

        protected function setUp(): void
        {
            $_SERVER = [];
            $_ENV['WEBHOOK_SECRET'] = '';
            self::$headers = [];
            self::$input = fopen('php://memory', 'r+');
        }

        /**
         * Записывает строку в поток php://memory.
         *
         * @param string $data Данные для записи.
         */
        private function setInput(string $data): void
        {
            fwrite(self::$input, $data);
            rewind(self::$input);
        }

        /**
         * Возвращает минимальный валидный JSON-апдейт Telegram.
         */
        private function sampleUpdate(): string
        {
            // Идентификаторы передаются строками, чтобы проверить приведение к int.
            return json_encode([
                'message' => [
                    'chat' => ['id' => '1'],
                    'from' => ['id' => '42', 'language_code' => 'ru'],
                    'text' => 'test',
                ],
            ], JSON_UNESCAPED_UNICODE);
        }

        /**
         * Создаёт временный каталог с .env-файлом.
         *
         * @param array<string,string> $vars Переменные окружения для записи.
         * @return string Путь к каталогу с файлом окружения.
         */
        private function createEnv(array $vars): string
        {
            $dir = sys_get_temp_dir() . '/env' . uniqid();
            mkdir($dir);
            $lines = [];
            foreach ($vars as $k => $v) {
                $lines[] = $k . '=' . $v;
            }
            file_put_contents($dir . '/.env.bracelet', implode("\n", $lines));
            return $dir;
        }

        /**
         * Запускает webhook.php с заданными параметрами запроса
         * и возвращает установленный HTTP-код.
         *
         * @param string               $body     Тело запроса.
         * @param array<string,string> $headers  Набор заголовков.
         * @param array<string,string> $server   Значения $_SERVER.
         * @param array<string,string> $envExtra Дополнительные переменные окружения.
         *
         * @return int HTTP-код ответа.
         */
        private function runWebhook(
            string $body,
            array $headers,
            array $server,
            array $envExtra = []
        ): int {
            self::$input = fopen('php://memory', 'r+');
            fwrite(self::$input, $body);
            rewind(self::$input);
            self::$headers = $headers;
            $_SERVER = $server;

            $envVars = array_merge([
                'BOT_TOKEN'   => 'dummy',
                'DB_NAME'     => 'test',
                'DB_USER'     => 'user',
                'DB_PASSWORD' => 'pass',
                'DB_DSN'      => 'sqlite::memory:',
                'API_URL'     => 'file:///',
            ], $envExtra);

            $envDir = $this->createEnv($envVars);
            putenv('DOTENV_PATH=' . $envDir);
            foreach ($envVars as $k => $v) {
                $_ENV[$k] = $v;
            }

            $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../bracelet/webhook.php';
            http_response_code(200);
            ob_start();
            include __DIR__ . '/../bracelet/webhook.php';
            ob_end_clean();
            $code = http_response_code();

            unlink($envDir . '/.env.bracelet');
            rmdir($envDir);
            return $code;
        }

        /**
         * Неподходящий IP должен приводить к InvalidIpException
         * и установке HTTP-кода 403.
         *
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testInvalidIp(): void
        {
            $body = $this->sampleUpdate();
            $headers = ['Content-Length' => (string) strlen($body)];
            $server = ['REMOTE_ADDR' => '8.8.8.8'];

            $this->setInput($body);
            self::$headers = $headers;
            $_SERVER = $server;

            try {
                (new RequestHandler())->handle();
                $this->fail('Ожидалось исключение InvalidIpException');
            } catch (InvalidIpException $e) {
                $this->assertTrue(true);
            }

            $code = $this->runWebhook($body, $headers, $server);
            $this->assertSame(403, $code);
        }

        /**
         * Неверный секретный токен вызывает InvalidTokenException
         * и приводит к HTTP-коду 403.
         *
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testInvalidSecret(): void
        {
            $body = $this->sampleUpdate();
            $headers = [
                'Content-Length' => (string) strlen($body),
                'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
            ];
            $server = ['REMOTE_ADDR' => '149.154.160.1'];

            $this->setInput($body);
            self::$headers = $headers;
            $_SERVER = $server;
            $_ENV['WEBHOOK_SECRET'] = 'secret';

            try {
                (new RequestHandler())->handle();
                $this->fail('Ожидалось исключение InvalidTokenException');
            } catch (InvalidTokenException $e) {
                $this->assertTrue(true);
            }

            $code = $this->runWebhook($body, $headers, $server, ['WEBHOOK_SECRET' => 'secret']);
            $this->assertSame(403, $code);
        }

        /**
         * Некорректный JSON должен вызвать BadRequestException
         * и привести к HTTP-коду 400.
         *
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testInvalidJson(): void
        {
            $body = '{invalid';
            $headers = ['Content-Length' => (string) strlen($body)];
            $server = ['REMOTE_ADDR' => '149.154.160.1'];

            $this->setInput($body);
            self::$headers = $headers;
            $_SERVER = $server;

            try {
                (new RequestHandler())->handle();
                $this->fail('Ожидалось исключение BadRequestException');
            } catch (BadRequestException $e) {
                $this->assertTrue(true);
            }

            $code = $this->runWebhook($body, $headers, $server);
            $this->assertSame(400, $code);
        }

        /**
         * Слишком большой Content-Length вызывает OversizedBodyException
         * и приводит к HTTP-коду 413.
         *
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testOversizedContentLength(): void
        {
            $body = $this->sampleUpdate();
            $oversized = (string) (strlen($body) + 1048576); // больше лимита 1 МБ
            $headers = ['Content-Length' => $oversized];
            $server = ['REMOTE_ADDR' => '149.154.160.1'];

            $this->setInput($body);
            self::$headers = $headers;
            $_SERVER = $server;

            try {
                (new RequestHandler())->handle();
                $this->fail('Ожидалось исключение OversizedBodyException');
            } catch (OversizedBodyException $e) {
                $this->assertTrue(true);
            }

            $code = $this->runWebhook($body, $headers, $server);
            $this->assertSame(413, $code);
        }
    }
}
