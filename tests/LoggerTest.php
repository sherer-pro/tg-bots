<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bracelet/logger.php';

/**
 * Тесты функции logError, отвечающей за запись сообщений в лог.
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

        // Пишем сообщение в лог.
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
}
