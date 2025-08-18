<?php

use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты webhook-скрипта браслета.
 */
final class WebhookTest extends TestCase
{
    /**
     * Убедимся, что в webhook используется функция processStep вместо switch.
     */
    public function testWebhookContainsProcessStepCall(): void
    {
        $code = file_get_contents(__DIR__ . '/../bracelet/webhook.php');
        $this->assertStringContainsString('processStep', $code);
        $this->assertStringNotContainsString('switch ($step)', $code);
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

        $env = [
            'BOT_TOKEN' => 'dummy',
            'DB_NAME' => 'test',
            'DB_USER' => 'user',
            'DB_PASSWORD' => 'pass',
            'DB_DSN' => 'sqlite:' . $dbFile,
            'API_URL' => 'file://' . $apiDir . '/',
            'WEBHOOK_SECRET' => '',
            'REMOTE_ADDR' => '8.8.8.8',
        ];

        $proc = proc_open(PHP_BINARY . ' bracelet/webhook.php', $descriptorSpec, $pipes, __DIR__ . '/..', $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $update);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);

        $pdoCheck = new PDO('sqlite:' . $dbFile);
        $row = $pdoCheck->query('SELECT step, data FROM user_state WHERE tg_user_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['step']);
        $this->assertSame('{}', $row['data']);
    }
}
