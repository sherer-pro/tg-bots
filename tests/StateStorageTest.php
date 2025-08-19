<?php

use Bracelet\StateStorage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для класса StateStorage.
 */
final class StateStorageTest extends TestCase
{
    /**
     * Убеждаемся, что при невозможности сериализовать данные
     * метод saveState выбрасывает исключение и пишет в лог.
     */
    public function testSaveStateLogsAndThrowsOnJsonError(): void
    {
        // Создаём временную базу данных SQLite и таблицу состояния
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT);');

        // Инициируем объект хранилища состояния
        $storage = new StateStorage($pdo);

        // Путь к лог-файлу приложения
        $logFile = __DIR__ . '/../bracelet/logs/app.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        // Создаём некорректные данные, содержащие ресурс
        $resource = fopen('php://memory', 'r');
        $data = ['broken' => $resource];

        // Ожидаем получение исключения RuntimeException
        $this->expectException(\RuntimeException::class);
        $storage->saveState(1, 2, $data);

        // Закрываем ресурс, чтобы не оставлять открытых дескрипторов
        fclose($resource);

        // Проверяем, что в лог был записан текст об ошибке
        $this->assertFileExists($logFile);
        $logContents = file_get_contents($logFile);
        $this->assertStringContainsString('Ошибка JSON при сохранении состояния пользователя', $logContents);
    }
}
