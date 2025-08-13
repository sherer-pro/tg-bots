<?php

use PHPUnit\Framework\TestCase;

/**
 * Проверяет, что функция loadBotEnv выбрасывает исключение, если
 * файл окружения отсутствует. Это гарантирует, что ошибки загрузки
 * можно отлавливать в тестах и сторонних скриптах.
 */
final class LoadEnvFailureTest extends TestCase
{
    /**
     * Попытка загрузить несуществующий файл `.env` должна привести
     * к выбросу RuntimeException.
     */
    public function testThrowsExceptionWhenEnvFileMissing(): void
    {
        require_once __DIR__ . '/../loadEnv.php';

        $this->expectException(RuntimeException::class);
        loadBotEnv('nonexistent');
    }
}
