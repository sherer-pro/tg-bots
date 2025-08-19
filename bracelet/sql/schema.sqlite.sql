-- Схема базы данных для SQLite.
-- Используйте этот файл при работе с SQLite.

-- Таблица логов расчётов браслета
CREATE TABLE log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT, -- уникальный идентификатор записи
    tg_user_id   INTEGER,                           -- идентификатор пользователя в Telegram
    wrist_cm     REAL,                              -- окружность запястья в сантиметрах
    wraps        INTEGER,                           -- количество оборотов верёвки
    pattern      TEXT,                              -- выбранный узор плетения
    magnet_mm    REAL,                              -- размер магнита в миллиметрах
    tolerance_mm REAL,                              -- допуск на погрешность в миллиметрах
    result_text  TEXT,                              -- текст с результатом расчёта
    created_at   TEXT DEFAULT CURRENT_TIMESTAMP     -- время создания записи
);

-- Текущее состояние пользователя при взаимодействии с ботом
CREATE TABLE user_state (
    tg_user_id INTEGER PRIMARY KEY,                 -- идентификатор пользователя в Telegram
    step       INTEGER NOT NULL,                    -- текущий шаг сценария
    data       TEXT NOT NULL DEFAULT '{}',          -- вспомогательные данные в формате JSON
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP       -- время последнего обновления
);
