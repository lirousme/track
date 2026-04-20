CREATE DATABASE IF NOT EXISTS track_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE track_app;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tracks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    parent_goal_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_goals_parent FOREIGN KEY (parent_goal_id) REFERENCES goals(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS habits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    goal_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    subjectivities TEXT NULL,
    repetition_kind ENUM('unlimited','count_limit','interval') NOT NULL DEFAULT 'unlimited',
    repetition_limit INT UNSIGNED NULL,
    repetition_count INT UNSIGNED NOT NULL DEFAULT 0,
    repetition_every_value INT UNSIGNED NULL,
    repetition_every_unit ENUM('minute','hour','day') NULL,
    repetition_start_at DATETIME NULL,
    repetition_end_at DATETIME NULL,
    next_due_at DATETIME NULL,
    last_check_at DATETIME NULL,
    schedule_cycle_kind ENUM('every_x_days','week_days','month_days') NOT NULL DEFAULT 'every_x_days',
    schedule_cycle_interval INT UNSIGNED NULL,
    schedule_week_days VARCHAR(32) NULL,
    schedule_month_days VARCHAR(128) NULL,
    intraday_mode ENUM('once','interval') NOT NULL DEFAULT 'once',
    intraday_every_value INT UNSIGNED NULL,
    intraday_every_unit ENUM('minute','hour') NULL,
    intraday_window_start TIME NULL,
    intraday_window_end TIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_habits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_habits_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    CONSTRAINT chk_habits_interval_value CHECK (
        repetition_kind <> 'interval' OR (repetition_every_value IS NOT NULL AND repetition_every_value > 0)
    ),
    CONSTRAINT chk_habits_interval_unit CHECK (
        repetition_kind <> 'interval' OR repetition_every_unit IS NOT NULL
    )
) ENGINE=InnoDB;

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

-- Usuário inicial (senha: senha123)
INSERT INTO users (username, password_hash)
VALUES ('admin', '$2y$12$qOThAZzKJVoA/aBP0Tm1X.hM91tGL.d41OrTj9aMkWcac5XID31Pe')
ON DUPLICATE KEY UPDATE username = VALUES(username);
