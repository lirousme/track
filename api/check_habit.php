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
    if (!in_array('subjectivities', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN subjectivities TEXT NULL AFTER title');
    }
    if (!in_array('repetition_limit', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_limit INT UNSIGNED NULL AFTER subjectivities');
    }
    if (!in_array('repetition_count', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER repetition_limit');
    }
    if (!in_array('repetition_kind', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN repetition_kind ENUM('unlimited','count_limit','interval') NOT NULL DEFAULT 'unlimited' AFTER subjectivities");
    }
    if (!in_array('repetition_every_value', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_every_value INT UNSIGNED NULL AFTER repetition_count');
    }
    if (!in_array('repetition_every_unit', $habitColumns, true)) {
        db()->exec("ALTER TABLE habits ADD COLUMN repetition_every_unit ENUM('minute','hour','day') NULL AFTER repetition_every_value");
    }
    if (!in_array('repetition_start_at', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_start_at DATETIME NULL AFTER repetition_every_unit');
    }
    if (!in_array('repetition_end_at', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_end_at DATETIME NULL AFTER repetition_start_at');
    }
    if (!in_array('next_due_at', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN next_due_at DATETIME NULL AFTER repetition_end_at');
    }
    if (!in_array('last_check_at', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN last_check_at DATETIME NULL AFTER next_due_at');
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
            repetition_every_value,
            repetition_every_unit,
            repetition_end_at,
            next_due_at
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
    $repetitionCount = (int) $habit['repetition_count'];
    $now = new DateTimeImmutable('now');

    if ($repetitionKind === 'count_limit' && $repetitionLimit !== null && $repetitionCount >= $repetitionLimit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Esse hábito já atingiu o limite de repetições.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $nextDueAtUpdate = null;
    if ($repetitionKind === 'interval') {
        $everyValue = $habit['repetition_every_value'] !== null ? (int) $habit['repetition_every_value'] : null;
        $everyUnit = (string) ($habit['repetition_every_unit'] ?? '');
        $currentNextDueAtRaw = $habit['next_due_at'] !== null ? (string) $habit['next_due_at'] : '';
        $endAtRaw = $habit['repetition_end_at'] !== null ? (string) $habit['repetition_end_at'] : '';

        if ($everyValue === null || $everyValue <= 0 || !in_array($everyUnit, ['minute', 'hour', 'day'], true) || $currentNextDueAtRaw === '') {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Esse hábito tem configuração de intervalo inválida.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        $currentNextDueAt = new DateTimeImmutable($currentNextDueAtRaw);
        if ($now < $currentNextDueAt) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Esse hábito ainda não está disponível para check.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        if ($endAtRaw !== '' && $now > new DateTimeImmutable($endAtRaw)) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Esse hábito já passou da data final de repetição.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        if ($everyUnit === 'minute') {
            $interval = new DateInterval('PT' . $everyValue . 'M');
        } elseif ($everyUnit === 'hour') {
            $interval = new DateInterval('PT' . $everyValue . 'H');
        } else {
            $interval = new DateInterval('P' . $everyValue . 'D');
        }

        $nextDueAt = $currentNextDueAt->add($interval);
        while ($nextDueAt <= $now) {
            $nextDueAt = $nextDueAt->add($interval);
        }

        $nextDueAtUpdate = $nextDueAt->format('Y-m-d H:i:s');
    }

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET
            repetition_count = repetition_count + 1,
            last_check_at = :last_check_at,
            next_due_at = :next_due_at
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'last_check_at' => $now->format('Y-m-d H:i:s'),
        'next_due_at' => $nextDueAtUpdate,
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
