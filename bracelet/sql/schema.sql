CREATE TABLE log (
                     id           SERIAL PRIMARY KEY,
                     tg_user_id   BIGINT,
                     wrist_cm     NUMERIC(5,1),
                     wraps        INT,
                     pattern      TEXT,
                     magnet_mm    NUMERIC(5,1),
                     tolerance_mm NUMERIC(5,1),
                     result_text  TEXT,
                     created_at   TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE user_state (
    tg_user_id BIGINT PRIMARY KEY,
    step SMALLINT NOT NULL,
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ DEFAULT now()
);
