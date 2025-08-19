<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\logError;
use function Bracelet\logInfo;

/**
 * Тесты функций логирования, отвечающих за запись сообщений в лог.
 *
 * Проверяется корректное удаление переводов строк
 * как для ошибок, так и для информационных сообщений.
 */
final class LoggerTest extends TestCase
{
    /**
     * Путь к временному лог-файлу, используемому в тестах.
     *
     * @var string
     */
    private string $logFile;

    /**
     * Подготавливает путь к временному файлу логов перед запуском теста.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Создаём уникальный файл в каталоге системных временных файлов.
        $this->logFile = tempnam(sys_get_temp_dir(), 'logger_');
        // Удаляем файл, чтобы логгер создал его заново при записи.
        unlink($this->logFile);

        // Устанавливаем переменную окружения LOG_FILE, чтобы логгер
        // писал именно в этот путь.
        putenv('LOG_FILE=' . $this->logFile);
    }

    /**
     * Удаляет временный лог-файл и очищает переменную окружения.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Стираем созданный файл, если он появился.
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        // Удаляем переменную окружения, чтобы не влиять на другие тесты.
        putenv('LOG_FILE');

        parent::tearDown();
    }
    /**
     * Проверяет, что сообщение с переводами строк записывается в лог
     * без сырых символов \n и \r, которые могли бы исказить структуру файла.
     */
    public function testMessageSanitization(): void
    {
        // Формируем сообщение, содержащее перевод строки.
        $rawMessage = "первая строка\nвторая строка";

        // Пишем сообщение об ошибке в лог.
        logError($rawMessage);

        // Читаем последнюю строку из файла логов.
        $lines = file($this->logFile);
        $lastLine = rtrim(end($lines), "\r\n");

        // Проверяем, что перевод строки заменён на пробел и нет сырых символов.
        $this->assertStringContainsString('первая строка вторая строка', $lastLine);
        $this->assertStringNotContainsString("\n", $lastLine);
        $this->assertStringNotContainsString("\r", $lastLine);

    }

    /**
     * Убеждаемся, что logInfo также корректно обрабатывает переводы строк.
     */
    public function testInfoMessageSanitization(): void
    {
        $rawMessage = "строка A\nстрока B";

        // Пишем информационное сообщение в лог
        logInfo($rawMessage);

        $lines = file($this->logFile);
        $lastLine = rtrim(end($lines), "\r\n");

        // Проверяем, что в логе нет символов перевода строки.
        $this->assertStringContainsString('строка A строка B', $lastLine);
        $this->assertStringNotContainsString("\n", $lastLine);
        $this->assertStringNotContainsString("\r", $lastLine);

    }
}
