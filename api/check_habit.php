<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$habitId = (int) ($_POST['habit_id'] ?? 0);

if ($habitId <= 0) {
    $_SESSION['flash_error'] = 'Hábito inválido.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

try {
    db()->beginTransaction();

    $habitColumns = db()->query('SHOW COLUMNS FROM habits')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('repetition_limit', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_limit INT UNSIGNED NULL AFTER subjectivities');
    }
    if (!in_array('repetition_count', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER repetition_limit');
    }
    if (!in_array('repetition_kind', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN repetition_kind ENUM('unlimited','count_limit','interval') NOT NULL DEFAULT 'unlimited' AFTER subjectivities");
    }
    if (!in_array('last_check_at', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN last_check_at DATETIME NULL AFTER next_due_at');
    }
    if (!in_array('schedule_cycle_kind', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN schedule_cycle_kind ENUM('every_x_days','week_days','month_days') NOT NULL DEFAULT 'every_x_days' AFTER last_check_at");
    }
    if (!in_array('schedule_cycle_interval', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN schedule_cycle_interval INT UNSIGNED NULL AFTER schedule_cycle_kind');
    }
    if (!in_array('schedule_week_days', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN schedule_week_days VARCHAR(32) NULL AFTER schedule_cycle_interval');
    }
    if (!in_array('schedule_month_days', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN schedule_month_days VARCHAR(128) NULL AFTER schedule_week_days');
    }
    if (!in_array('intraday_mode', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN intraday_mode ENUM('once','interval') NOT NULL DEFAULT 'once' AFTER schedule_month_days");
    }
    if (!in_array('intraday_every_value', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN intraday_every_value INT UNSIGNED NULL AFTER intraday_mode');
    }
    if (!in_array('intraday_every_unit', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN intraday_every_unit ENUM('minute','hour') NULL AFTER intraday_every_value");
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS habit_repetition_events (
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
        ) ENGINE=InnoDB'
    );

    $habitStmt = db()->prepare(
        'SELECT
            id,
            repetition_kind,
            repetition_limit,
            repetition_count,
            created_at,
            last_check_at,
            schedule_cycle_kind,
            schedule_cycle_interval,
            schedule_week_days,
            schedule_month_days,
            intraday_mode,
            intraday_every_value,
            intraday_every_unit
         FROM habits
         WHERE id = :id AND user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $habitStmt->execute([
        'id' => $habitId,
        'user_id' => $userId,
    ]);
    $habit = $habitStmt->fetch();

    if (!$habit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Hábito não encontrado.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $repetitionKind = (string) ($habit['repetition_kind'] ?? 'unlimited');
    $repetitionLimit = $habit['repetition_limit'] !== null ? (int) $habit['repetition_limit'] : null;
    $repetitionCount = (int) ($habit['repetition_count'] ?? 0);
    $now = new DateTimeImmutable('now');

    if (($repetitionKind === 'count_limit' || $repetitionLimit !== null) && $repetitionLimit !== null && $repetitionCount >= $repetitionLimit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Esse hábito já atingiu o limite de repetições.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $cycleKind = (string) ($habit['schedule_cycle_kind'] ?? 'every_x_days');
    $todayDayOfMonth = (int) $now->format('j');
    $todayIsoWeekDay = (int) $now->format('N');

    $isCycleMatch = false;
    if ($cycleKind === 'every_x_days') {
        $interval = (int) ($habit['schedule_cycle_interval'] ?? 1);
        if ($interval <= 0) {
            $interval = 1;
        }
        $createdAt = new DateTimeImmutable((string) $habit['created_at']);
        $daysDiff = (int) $createdAt->setTime(0, 0)->diff($now->setTime(0, 0))->format('%a');
        $isCycleMatch = $daysDiff % $interval === 0;
    } elseif ($cycleKind === 'week_days') {
        $weekDays = array_filter(explode(',', (string) ($habit['schedule_week_days'] ?? '')));
        $isCycleMatch = in_array((string) $todayIsoWeekDay, $weekDays, true);
    } elseif ($cycleKind === 'month_days') {
        $monthDays = array_filter(explode(',', (string) ($habit['schedule_month_days'] ?? '')));
        $isCycleMatch = in_array((string) $todayDayOfMonth, $monthDays, true);
    }

    if (!$isCycleMatch) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Hoje não é um dia válido para esse hábito.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $lastCheckAtRaw = $habit['last_check_at'] !== null ? (string) $habit['last_check_at'] : '';
    $lastCheckAt = $lastCheckAtRaw !== '' ? new DateTimeImmutable($lastCheckAtRaw) : null;
    $intradayMode = (string) ($habit['intraday_mode'] ?? 'once');

    if ($intradayMode === 'once') {
        if ($lastCheckAt !== null && $lastCheckAt->format('Y-m-d') === $now->format('Y-m-d')) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Esse hábito já foi marcado hoje.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }
    } elseif ($intradayMode === 'interval') {
        $everyValue = (int) ($habit['intraday_every_value'] ?? 0);
        $everyUnit = (string) ($habit['intraday_every_unit'] ?? '');
        if ($everyValue <= 0 || !in_array($everyUnit, ['minute', 'hour'], true)) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Esse hábito tem intervalo no dia inválido.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        if ($lastCheckAt !== null && $lastCheckAt->format('Y-m-d') === $now->format('Y-m-d')) {
            $seconds = $everyUnit === 'minute' ? $everyValue * 60 : $everyValue * 3600;
            if (($now->getTimestamp() - $lastCheckAt->getTimestamp()) < $seconds) {
                db()->rollBack();
                $_SESSION['flash_error'] = 'Ainda não passou o intervalo mínimo para um novo check hoje.';
                header('Location: ' . trackUrl('/index.php?view=track'));
                exit;
            }
        }
    } else {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Configuração de repetição no dia inválida.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET
            repetition_count = repetition_count + 1,
            last_check_at = :last_check_at
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'last_check_at' => $now->format('Y-m-d H:i:s'),
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    $eventStmt = db()->prepare(
        'INSERT INTO habit_repetition_events (habit_id, user_id, checked_at, note)
         VALUES (:habit_id, :user_id, :checked_at, NULL)'
    );
    $eventStmt->execute([
        'habit_id' => $habitId,
        'user_id' => $userId,
        'checked_at' => $now->format('Y-m-d H:i:s'),
    ]);

    db()->commit();
    $_SESSION['flash_success'] = 'Repetição marcada com sucesso.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível marcar a repetição.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
