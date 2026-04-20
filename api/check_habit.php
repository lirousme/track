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
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_limit INT UNSIGNED NULL AFTER notes');
    }
    if (!in_array('repetition_count', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN repetition_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER repetition_limit');
    }

    $habitStmt = db()->prepare(
        'SELECT id, repetition_limit, repetition_count
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

    $repetitionLimit = $habit['repetition_limit'] !== null ? (int) $habit['repetition_limit'] : null;
    $repetitionCount = (int) $habit['repetition_count'];

    if ($repetitionLimit !== null && $repetitionCount >= $repetitionLimit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Esse hábito já atingiu o limite de repetições.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET repetition_count = repetition_count + 1
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'id' => $habitId,
        'user_id' => $userId,
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
