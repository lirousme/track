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
    $deleteStmt = db()->prepare(
        'DELETE FROM habits
         WHERE id = :id AND user_id = :user_id'
    );
    $deleteStmt->execute([
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    if ($deleteStmt->rowCount() === 0) {
        $_SESSION['flash_error'] = 'Hábito não encontrado.';
    } else {
        $_SESSION['flash_success'] = 'Hábito excluído com sucesso.';
    }
} catch (Throwable $exception) {
    $_SESSION['flash_error'] = 'Não foi possível excluir o hábito.';
}

header('Location: ' . trackUrl('/index.php?view=track'));
exit;
