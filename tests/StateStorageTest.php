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

    /**
     * Проверяем, что при чтении состояния с повреждённым JSON
     * метод getState выбрасывает исключение и пишет сообщение в лог.
     */
    public function testGetStateLogsAndThrowsOnInvalidJson(): void
    {
        // Создаём временную базу данных SQLite и таблицу состояния
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT);');

        // Вставляем запись с некорректным JSON в поле data
        $pdo->exec("INSERT INTO user_state (tg_user_id, step, data) VALUES (1, 3, '{invalid')");

        // Инициализируем объект хранилища состояния
        $storage = new StateStorage($pdo);

        // Путь к лог-файлу
        $logFile = __DIR__ . '/../bracelet/logs/app.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        // Ожидаем, что метод getState выбросит исключение
        $this->expectException(\RuntimeException::class);
        $storage->getState(1);

        // Проверяем, что в лог записано сообщение об ошибке
        $this->assertFileExists($logFile);
        $logContents = file_get_contents($logFile);
        $this->assertStringContainsString('Ошибка JSON при чтении состояния пользователя', $logContents);
    }

    /**
     * Проверяем, что метод saveResult реагирует на отсутствие
     * обязательных ключей в данных: фиксирует ошибку в лог и
     * выбрасывает исключение RuntimeException.
     *
     * @dataProvider provideMissingKeys
     */
    public function testSaveResultLogsAndThrowsOnMissingKey(string $missingKey): void
    {
        // Создаём временную базу SQLite и необходимые таблицы.
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT);');
        $pdo->exec('CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, tg_user_id INTEGER, wrist_cm REAL, wraps INTEGER, pattern TEXT, magnet_mm REAL, tolerance_mm REAL, result_text TEXT, created_at TEXT);');

        $storage = new StateStorage($pdo);

        // Полный набор корректных данных для сохранения результата.
        $data = [
            'wrist_cm' => 16.5,
            'wraps' => 3,
            'pattern' => 'demo',
            'magnet_mm' => 5.0,
            'tolerance_mm' => 0.5,
        ];
        // Искусственно удаляем один из обязательных ключей.
        unset($data[$missingKey]);

        // Очищаем лог-файл перед выполнением теста.
        $logFile = __DIR__ . '/../bracelet/logs/app.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        // Ожидаем исключение и одновременно проверяем, что ошибка
        // записана в лог-файл даже при его возникновении.
        $this->expectException(\RuntimeException::class);
        try {
            $storage->saveResult(1, $data, 'res');
        } finally {
            $this->assertFileExists($logFile);
            $logContents = file_get_contents($logFile);
            $this->assertStringContainsString('Отсутствует обязательный ключ', $logContents);
            $this->assertStringContainsString($missingKey, $logContents);
        }
    }

    /**
     * Набор обязательных ключей для проверки в тесте saveResult.
     *
     * @return iterable<array{0:string}>
     */
    public static function provideMissingKeys(): iterable
    {
        yield ['wrist_cm'];
        yield ['wraps'];
        yield ['pattern'];
        yield ['magnet_mm'];
        yield ['tolerance_mm'];
    }
}
