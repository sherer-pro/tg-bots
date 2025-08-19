<?php

use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты webhook-скрипта браслета.
 */
final class WebhookTest extends TestCase
{
    /**
     * Создаёт временный каталог с `.env`-файлом и возвращает его путь.
     *
     * Файл содержит пустой `WEBHOOK_SECRET`, что позволяет запускать
     * веб-хук без проверки секретного заголовка.
     *
     * @return string Путь к каталогу с временным `.env`.
     */
    private function createTempEnvDir(): string
    {
        $dir = sys_get_temp_dir() . '/env' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env.bracelet', "WEBHOOK_SECRET=\n");
        return $dir;
    }
    /**
     * Проверяем, что webhook реализован классом и использует функцию
     * processStep вместо switch, а index.php создаёт этот класс.
     */
    public function testWebhookUsesProcessorClass(): void
    {
        $webhookCode = file_get_contents(__DIR__ . '/../bracelet/webhook.php');
        $this->assertStringContainsString('class WebhookProcessor', $webhookCode);
        $this->assertStringContainsString('processStep', $webhookCode);
        $this->assertStringNotContainsString('switch ($step)', $webhookCode);
        $this->assertStringNotContainsString('function runWebhook', $webhookCode);

        $indexCode = file_get_contents(__DIR__ . '/../index.php');
        $this->assertStringContainsString('new WebhookProcessor()', $indexCode);
    }

    /**
     * Даже без секрета запрос с IP, не принадлежащего Telegram,
     * должен быть отклонён.
     */
    public function testRejectsForeignIpWithoutSecret(): void
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT);');
        $pdo->exec('CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, tg_user_id INTEGER, wrist_cm REAL, wraps INTEGER, pattern TEXT, magnet_mm REAL, tolerance_mm REAL, result_text TEXT, created_at TEXT);');
        $pdo->exec("INSERT INTO user_state (tg_user_id, step, data) VALUES (42, 1, '{}');");

        $apiDir = sys_get_temp_dir() . '/api' . uniqid();
        mkdir($apiDir);
        file_put_contents($apiDir . '/sendMessage', json_encode(['ok' => true]));

        $update = json_encode([
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 42, 'language_code' => 'ru'],
                'text' => '15',
            ],
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envDir = $this->createTempEnvDir();
        $env = [
            'BOT_TOKEN'      => 'dummy',
            'DB_NAME'        => 'test',
            'DB_USER'        => 'user',
            'DB_PASSWORD'    => 'pass',
            'DB_DSN'         => 'sqlite:' . $dbFile,
            'API_URL'        => 'file://' . $apiDir . '/',
            'REMOTE_ADDR'    => '8.8.8.8',
            'DOTENV_PATH'    => $envDir,
        ];

        $proc = proc_open(PHP_BINARY . ' bracelet/webhook.php', $descriptorSpec, $pipes, __DIR__ . '/..', $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $update);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);

        // Удаляем временный .env-файл.
        unlink($envDir . '/.env.bracelet');
        rmdir($envDir);

        $pdoCheck = new PDO('sqlite:' . $dbFile);
        $row = $pdoCheck->query('SELECT step, data FROM user_state WHERE tg_user_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['step']);
        $this->assertSame('{}', $row['data']);
    }

    /**
     * Запросы с телом больше 1 МБ должны быть отвергнуты
     * с кодом ответа 413 и записью в лог.
     */
    public function testRejectsOversizedBody(): void
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'db');
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE user_state (tg_user_id INTEGER PRIMARY KEY, step INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT);');
        $pdo->exec('CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, tg_user_id INTEGER, wrist_cm REAL, wraps INTEGER, pattern TEXT, magnet_mm REAL, tolerance_mm REAL, result_text TEXT, created_at TEXT);');
        $pdo->exec("INSERT INTO user_state (tg_user_id, step, data) VALUES (42, 1, '{}');");

        $apiDir = sys_get_temp_dir() . '/api' . uniqid();
        mkdir($apiDir);
        file_put_contents($apiDir . '/sendMessage', json_encode(['ok' => true]));

        // Формируем строку, превышающую допустимый размер 1 МБ.
        $oversizedBody = str_repeat('A', 1024 * 1024 + 1);

        // Перед запуском очищаем лог-файл, чтобы в нём осталась только наша запись.
        $logFile = __DIR__ . '/../bracelet/logs/app.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envDir = $this->createTempEnvDir();
        $env = [
            'BOT_TOKEN'      => 'dummy',
            'DB_NAME'        => 'test',
            'DB_USER'        => 'user',
            'DB_PASSWORD'    => 'pass',
            'DB_DSN'         => 'sqlite:' . $dbFile,
            'API_URL'        => 'file://' . $apiDir . '/',
            // IP, принадлежащий диапазонам Telegram, чтобы пройти проверку.
            'REMOTE_ADDR'    => '149.154.160.1',
            // Передаём заголовок Content-Length через переменную окружения.
            'CONTENT_LENGTH' => (string) strlen($oversizedBody),
            'DOTENV_PATH'    => $envDir,
        ];

        $proc = proc_open(PHP_BINARY . ' bracelet/webhook.php', $descriptorSpec, $pipes, __DIR__ . '/..', $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $oversizedBody);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);

        // Удаляем временный .env-файл.
        unlink($envDir . '/.env.bracelet');
        rmdir($envDir);

        // Проверяем, что лог содержит запись о превышении размера запроса.
        $this->assertFileExists($logFile);
        $logContents = file_get_contents($logFile);
        $this->assertStringContainsString('Превышен допустимый размер запроса', $logContents);

        // Убеждаемся, что состояние пользователя не изменилось.
        $pdoCheck = new PDO('sqlite:' . $dbFile);
        $row = $pdoCheck->query('SELECT step, data FROM user_state WHERE tg_user_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['step']);
        $this->assertSame('{}', $row['data']);
    }
}
