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
$subjectivities = trim((string) ($_POST['subjectivities'] ?? ''));

if ($habitId <= 0) {
    $_SESSION['flash_error'] = 'Hábito inválido.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

if (mb_strlen($subjectivities) > 2000) {
    $_SESSION['flash_error'] = 'As subjetividades devem ter no máximo 2000 caracteres.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

try {
    db()->beginTransaction();

    $habitColumns = db()->query('SHOW COLUMNS FROM habits')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('subjectivities', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN subjectivities TEXT NULL AFTER title');
    }

    $habitCheck = db()->prepare('SELECT id FROM habits WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE');
    $habitCheck->execute([
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    if (!$habitCheck->fetch()) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Hábito não encontrado.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET subjectivities = :subjectivities
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'subjectivities' => $subjectivities !== '' ? $subjectivities : null,
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    db()->commit();

    $_SESSION['flash_success'] = 'Subjetividades atualizadas com sucesso.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível atualizar as subjetividades.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
