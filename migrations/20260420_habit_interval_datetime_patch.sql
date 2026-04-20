-- Patch para instalações antigas do Track (modelo anterior)
-- Objetivo: permitir repetições por intervalo com data/hora
-- Exemplos: a cada 2 dias, 6 horas, 30 minutos

USE track_app;

-- 1) Expande a tabela habits com campos de agendamento
ALTER TABLE habits
    ADD COLUMN IF NOT EXISTS repetition_kind ENUM('unlimited','count_limit','interval')
        NOT NULL DEFAULT 'unlimited' AFTER subjectivities,
    ADD COLUMN IF NOT EXISTS repetition_every_value INT UNSIGNED NULL
        AFTER repetition_kind,
    ADD COLUMN IF NOT EXISTS repetition_every_unit ENUM('minute','hour','day') NULL
        AFTER repetition_every_value,
    ADD COLUMN IF NOT EXISTS repetition_start_at DATETIME NULL
        AFTER repetition_every_unit,
    ADD COLUMN IF NOT EXISTS repetition_end_at DATETIME NULL
        AFTER repetition_start_at,
    ADD COLUMN IF NOT EXISTS next_due_at DATETIME NULL
        AFTER repetition_end_at,
    ADD COLUMN IF NOT EXISTS last_check_at DATETIME NULL
        AFTER next_due_at;

-- 2) Mantém compatibilidade com o modo antigo de limite por quantidade
ALTER TABLE habits
    MODIFY COLUMN repetition_limit INT UNSIGNED NULL,
    MODIFY COLUMN repetition_count INT UNSIGNED NOT NULL DEFAULT 0;

-- 3) Regras de integridade para o modo interval
-- Observação: em MySQL, ADD CONSTRAINT IF NOT EXISTS não é suportado em versões comuns.
-- Se já existir constraint com o mesmo nome, remova essa linha ou renomeie.
ALTER TABLE habits
    ADD CONSTRAINT chk_habits_interval_value
        CHECK (
            repetition_kind <> 'interval'
            OR (repetition_every_value IS NOT NULL AND repetition_every_value > 0)
        ),
    ADD CONSTRAINT chk_habits_interval_unit
        CHECK (
            repetition_kind <> 'interval'
            OR repetition_every_unit IS NOT NULL
        );

-- 4) Histórico das marcações com data/hora de cada repetição
CREATE TABLE IF NOT EXISTS habit_repetition_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    habit_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_habit_events_habit FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE,
    CONSTRAINT fk_habit_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_habit_events_habit_checked_at (habit_id, checked_at),
    INDEX idx_habit_events_user_checked_at (user_id, checked_at)
) ENGINE=InnoDB;
