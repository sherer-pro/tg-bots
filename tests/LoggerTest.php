<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\logError;
use function Bracelet\logInfo;
use const Bracelet\LOG_FILE;

/**
 * Тесты функций логирования, отвечающих за запись сообщений в лог.
 *
 * Проверяется корректное удаление переводов строк
 * как для ошибок, так и для информационных сообщений.
 */
final class LoggerTest extends TestCase
{
    /**
     * Проверяет, что сообщение с переводами строк записывается в лог
     * без сырых символов \n и \r, которые могли бы исказить структуру файла.
     */
    public function testMessageSanitization(): void
    {
        // При необходимости удаляем предыдущий файл логов,
        // чтобы тест не зависел от имеющегося содержимого.
        if (file_exists(LOG_FILE)) {
            unlink(LOG_FILE);
        }

        // Формируем сообщение, содержащее перевод строки.
        $rawMessage = "первая строка\nвторая строка";

        // Пишем сообщение об ошибке в лог.
        logError($rawMessage);

        // Читаем последнюю строку из файла логов.
        $lines = file(LOG_FILE);
        $lastLine = rtrim(end($lines), "\r\n");

        // Проверяем, что перевод строки заменён на пробел и нет сырых символов.
        $this->assertStringContainsString('первая строка вторая строка', $lastLine);
        $this->assertStringNotContainsString("\n", $lastLine);
        $this->assertStringNotContainsString("\r", $lastLine);

        // Удаляем файл логов после теста, чтобы не оставлять следов.
        unlink(LOG_FILE);
    }

    /**
     * Убеждаемся, что logInfo также корректно обрабатывает переводы строк.
     */
    public function testInfoMessageSanitization(): void
    {
        if (file_exists(LOG_FILE)) {
            unlink(LOG_FILE);
        }

        $rawMessage = "строка A\nстрока B";

        // Пишем информационное сообщение в лог
        logInfo($rawMessage);

        $lines = file(LOG_FILE);
        $lastLine = rtrim(end($lines), "\r\n");

        // Проверяем, что в логе нет символов перевода строки.
        $this->assertStringContainsString('строка A строка B', $lastLine);
        $this->assertStringNotContainsString("\n", $lastLine);
        $this->assertStringNotContainsString("\r", $lastLine);

        unlink(LOG_FILE);
    }
}
