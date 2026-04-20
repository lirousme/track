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

    $habitStmt = db()->prepare(
        'SELECT id, repetition_count
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

    $repetitionCount = (int) ($habit['repetition_count'] ?? 0);
    if ($repetitionCount <= 0) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Esse hábito já está com 0 revisões.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET repetition_count = repetition_count - 1
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'id' => $habitId,
        'user_id' => $userId,
    ]);

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

    $deleteEventStmt = db()->prepare(
        'DELETE FROM habit_repetition_events
         WHERE habit_id = :habit_id AND user_id = :user_id
         ORDER BY checked_at DESC, id DESC
         LIMIT 1'
    );
    $deleteEventStmt->execute([
        'habit_id' => $habitId,
        'user_id' => $userId,
    ]);

    db()->commit();
    $_SESSION['flash_success'] = 'Última revisão desmarcada com sucesso.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível desmarcar a revisão.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
