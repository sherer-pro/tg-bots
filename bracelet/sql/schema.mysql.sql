-- Схема базы данных для MySQL.
-- Используйте этот файл при работе с MySQL или MariaDB.

-- Таблица логов расчётов браслета
CREATE TABLE log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, -- уникальный идентификатор записи
    tg_user_id   BIGINT,                                     -- идентификатор пользователя в Telegram
    wrist_cm     DECIMAL(5,1),                               -- окружность запястья в сантиметрах
    wraps        INT,                                        -- количество оборотов верёвки
    pattern      TEXT,                                       -- выбранный узор плетения
    magnet_mm    DECIMAL(5,1),                               -- размер магнита в миллиметрах
    tolerance_mm DECIMAL(5,1),                               -- допуск на погрешность в миллиметрах
    result_text  TEXT,                                       -- текст с результатом расчёта
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP         -- время создания записи
) ENGINE=InnoDB;

-- Текущее состояние пользователя при взаимодействии с ботом
CREATE TABLE user_state (
    tg_user_id BIGINT PRIMARY KEY,                           -- идентификатор пользователя в Telegram
    step       SMALLINT NOT NULL,                            -- текущий шаг сценария
    data       JSON NOT NULL,                                -- вспомогательные данные в формате JSON
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP -- время последнего обновления
) ENGINE=InnoDB;
