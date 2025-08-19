-- Схема базы данных для PostgreSQL.
-- Альтернативные диалекты находятся в файлах schema.sqlite.sql и schema.mysql.sql.

-- Таблица логов расчётов браслета
CREATE TABLE log (
    id           SERIAL PRIMARY KEY,          -- уникальный идентификатор записи
    tg_user_id   BIGINT,                      -- идентификатор пользователя в Telegram
    wrist_cm     NUMERIC(5,1),                -- окружность запястья в сантиметрах
    wraps        INT,                         -- количество оборотов верёвки
    pattern      TEXT,                        -- выбранный узор плетения
    magnet_mm    NUMERIC(5,1),                -- размер магнита в миллиметрах
    tolerance_mm NUMERIC(5,1),                -- допуск на погрешность в миллиметрах
    result_text  TEXT,                        -- текст с результатом расчёта
    created_at   TIMESTAMPTZ DEFAULT now()    -- время создания записи
);

-- Текущее состояние пользователя при взаимодействии с ботом
CREATE TABLE user_state (
    tg_user_id BIGINT PRIMARY KEY,            -- идентификатор пользователя в Telegram
    step       SMALLINT NOT NULL,             -- текущий шаг сценария
    data       JSONB NOT NULL DEFAULT '{}'::jsonb, -- вспомогательные данные в формате JSON
    updated_at TIMESTAMPTZ DEFAULT now()      -- время последнего обновления
);
