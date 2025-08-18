<?php

use PHPUnit\Framework\TestCase;

/**
 * Тесты функции resolveRemoteIp, определяющей IP-адрес клиента.
 */
final class ZResolveRemoteIpTest extends TestCase
{
    /**
     * Подключаем файл webhook.php, определяющий функцию resolveRemoteIp,
     * но без выполнения основного кода скрипта.
     */
    public static function setUpBeforeClass(): void
    {
        if (!defined('WEBHOOK_LIB')) {
            define('WEBHOOK_LIB', true);
        }
        require_once __DIR__ . '/../bracelet/webhook.php';
    }

    /**
     * При наличии заголовка X-Forwarded-For используется первый IP из списка.
     */
    public function testUsesForwardedForWhenPresent(): void
    {
        $headers = ['X-Forwarded-For' => '203.0.113.5, 198.51.100.23'];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('203.0.113.5', resolveRemoteIp($headers, $server, true));
    }

    /**
     * При отсутствии заголовка X-Forwarded-For берётся REMOTE_ADDR.
     */
    public function testFallsBackToRemoteAddr(): void
    {
        $headers = [];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('198.51.100.23', resolveRemoteIp($headers, $server));
    }

    /**
     * Если доверие к заголовку не выражено, используется REMOTE_ADDR даже при
     * наличии X-Forwarded-For.
     */
    public function testIgnoresForwardedForWhenNotTrusted(): void
    {
        $headers = ['X-Forwarded-For' => '203.0.113.5'];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('198.51.100.23', resolveRemoteIp($headers, $server));
    }

    /**
     * При некорректном IP в X-Forwarded-For используется REMOTE_ADDR.
     */
    public function testInvalidForwardedIpFallsBackToRemoteAddr(): void
    {
        $headers = ['X-Forwarded-For' => '999.999.999.999'];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('198.51.100.23', resolveRemoteIp($headers, $server, true));
    }
}
