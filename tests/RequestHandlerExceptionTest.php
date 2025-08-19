<?php

use PHPUnit\Framework\TestCase;
use Bracelet\RequestHandler;

/**
 * Набор тестов, проверяющих выброс собственных исключений RequestHandler.
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
}

