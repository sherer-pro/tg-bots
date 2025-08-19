<?php

declare(strict_types=1);

/**
 * Класс для работы с таблицами `user_state` и `log` базы данных.
 *
 * Обеспечивает чтение, сохранение и очистку состояния пользователя,
 * а также фиксацию результатов диалога в журнале.
 */
class StateStorage
{
    /** @var PDO Подключение к базе данных. */
    private PDO $pdo;

    /**
     * @param PDO $pdo Готовое подключение к базе данных.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Инициализирует состояние пользователя для начала сценария.
     * Удаляет предыдущие данные и вставляет новую запись на первом шаге.
     *
     * @param int $userId Идентификатор пользователя Telegram.
     *
     * @return void
     */
    public function initState(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
        $this->pdo->prepare('INSERT INTO user_state (tg_user_id, step, data) VALUES (?,1,?::jsonb)')
            ->execute([$userId, json_encode([], JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Возвращает текущее состояние пользователя.
     *
     * @param int $userId Идентификатор пользователя Telegram.
     *
     * @return array{step:int,data:array}|null Массив состояния или `null`, если диалог не начат.
     */
    public function getState(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT step, data FROM user_state WHERE tg_user_id = ?');
        $stmt->execute([$userId]);
        /** @var array{step:int,data:string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null; // Состояние отсутствует
        }
        return [
            'step' => (int)$row['step'],
            'data' => json_decode($row['data'], true) ?: [],
        ];
    }

    /**
     * Сохраняет текущий шаг диалога и данные пользователя.
     *
     * @param int   $userId Идентификатор пользователя Telegram.
     * @param int   $step   Номер следующего шага.
     * @param array $data   Накопленные данные пользователя.
     *
     * @return void
     */
    public function saveState(int $userId, int $step, array $data): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_state SET step = ?, data = ?, updated_at = CURRENT_TIMESTAMP WHERE tg_user_id = ?');
        $stmt->execute([$step, json_encode($data, JSON_UNESCAPED_UNICODE), $userId]);
    }

    /**
     * Сохраняет итоговый результат расчёта и очищает состояние пользователя.
     *
     * @param int    $userId      Идентификатор пользователя Telegram.
     * @param array  $data        Данные, собранные в ходе диалога.
     * @param string $resultText  Текст результата для записи в лог.
     *
     * @return void
     */
    public function saveResult(int $userId, array $data, string $resultText): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO log (tg_user_id,wrist_cm,wraps,pattern,magnet_mm,tolerance_mm,result_text) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $userId,
            $data['wrist_cm'],
            $data['wraps'],
            $data['pattern'],
            $data['magnet_mm'],
            $data['tolerance_mm'],
            $resultText,
        ]);
        $this->clearState($userId); // После сохранения результата состояние больше не нужно
    }

    /**
     * Удаляет состояние пользователя без сохранения результата.
     *
     * @param int $userId Идентификатор пользователя Telegram.
     *
     * @return void
     */
    public function clearState(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM user_state WHERE tg_user_id = ?')->execute([$userId]);
    }
}
