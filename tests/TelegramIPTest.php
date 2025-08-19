<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\ipInRange;
use function Bracelet\isTelegramIP;

/**
 * Тесты функций, проверяющих IP-адреса на принадлежность Telegram.
 */
final class TelegramIPTest extends TestCase
{
    /**
     * IP-адреса из опубликованных диапазонов Telegram должны
     * корректно распознаваться.
     *
     * @return void
     */
    public function testIsTelegramIpTrue(): void
    {
        $this->assertTrue(isTelegramIP('149.154.167.99')); // 149.154.160.0/20
        $this->assertTrue(isTelegramIP('91.108.4.123'));   // 91.108.4.0/22
        $this->assertTrue(isTelegramIP('2001:67c:4e8:fa::1'));
        $this->assertTrue(isTelegramIP('2001:b28:f23d::42'));
    }

    /**
     * IP-адреса, не принадлежащие Telegram, должны возвращать false.
     *
     * @return void
     */
    public function testIsTelegramIpFalse(): void
    {
        $this->assertFalse(isTelegramIP('8.8.8.8'));
        $this->assertFalse(isTelegramIP('2001:4860:4860::8888'));
    }

    /**
     * Проверяем работу ipInRange на IPv6 адресах.
     *
     * @return void
     */
    public function testIpv6Range(): void
    {
        $this->assertTrue(ipInRange('2001:67c:4e8:ff::1', '2001:67c:4e8::/48'));
        $this->assertFalse(ipInRange('2001:67c:4e9::1', '2001:67c:4e8::/48'));
    }

    /**
     * Неверный префикс (выходящий за пределы адреса) должен приводить к false.
     *
     * @return void
     */
    public function testInvalidPrefix(): void
    {
        // Префикс 40 для IPv4 превышает допустимые 32 бита
        $this->assertFalse(ipInRange('149.154.167.99', '149.154.160.0/40'));

        // Префикс 129 для IPv6 превышает допустимые 128 бит
        $this->assertFalse(ipInRange('2001:67c:4e8::1', '2001:67c:4e8::/129'));
    }
}
