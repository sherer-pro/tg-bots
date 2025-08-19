<?php

use Bracelet\StateStorage;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест работы StateStorage на SQLite.
 *
 * Проверяем, что методы initState и saveResult корректно выполняются
 * в окружении с базой данных SQLite.
 */
final class StateStorageSqliteInitResultTest extends TestCase
{
    public function testInitStateAndSaveResult(): void
    {
        // Создаём временную базу SQLite и необходимые таблицы.
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->exec("CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL DEFAULT '{}', updated_at TEXT)");
        $pdo->exec("CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, tg_user_id INTEGER, wrist_cm REAL, wraps INTEGER, pattern TEXT, magnet_mm REAL, tolerance_mm REAL, result_text TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

        $storage = new StateStorage($pdo);

        // Инициализируем состояние и проверяем появление записи.
        $userId = 42;
        $storage->initState($userId);
        $stateRow = $pdo->query('SELECT step, data FROM user_state WHERE tg_user_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['step' => 1, 'data' => '[]'], $stateRow);

        // Подготавливаем тестовые данные для сохранения результата.
        $data = [
            'wrist_cm' => 16.5,
            'wraps' => 3,
            'pattern' => 'demo',
            'magnet_mm' => 5.0,
            'tolerance_mm' => 0.5,
        ];

        $storage->saveResult($userId, $data, 'ok');

        // После сохранения результата состояние пользователя должно быть очищено.
        $count = $pdo->query('SELECT COUNT(*) FROM user_state')->fetchColumn();
        $this->assertSame('0', (string)$count);

        // А запись с результатом должна появиться в журнале.
        $logRow = $pdo->query('SELECT result_text FROM log WHERE tg_user_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ok', $logRow['result_text']);
    }
}

