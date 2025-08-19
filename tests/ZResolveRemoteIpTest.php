<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\resolveRemoteIp;

/**
 * Тесты функции resolveRemoteIp, определяющей IP-адрес клиента.
 */
final class ZResolveRemoteIpTest extends TestCase
{

    /**
     * При наличии заголовка X-Forwarded-For используется первый IP из списка.
     */
    public function testUsesForwardedForWhenPresent(): void
    {
        // Заголовки приводим к нижнему регистру так же, как это делает вызывающий код
        $headers = ['x-forwarded-for' => '203.0.113.5, 198.51.100.23'];
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
        // Заголовок уже нормализован к нижнему регистру
        $headers = ['x-forwarded-for' => '203.0.113.5'];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('198.51.100.23', resolveRemoteIp($headers, $server));
    }

    /**
     * При некорректном IP в X-Forwarded-For используется REMOTE_ADDR.
     */
    public function testInvalidForwardedIpFallsBackToRemoteAddr(): void
    {
        // Используем намеренно некорректный IP, ключ заголовка в нижнем регистре
        $headers = ['x-forwarded-for' => '999.999.999.999'];
        $server  = ['REMOTE_ADDR' => '198.51.100.23'];
        $this->assertSame('198.51.100.23', resolveRemoteIp($headers, $server, true));
    }
}
