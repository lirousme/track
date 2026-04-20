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
$title = trim((string) ($_POST['title'] ?? ''));
$goalTitle = trim((string) ($_POST['goal_title'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$parentGoalId = $_POST['parent_goal_id'] ?? '';

if ($title === '' || $goalTitle === '') {
    $_SESSION['flash_error'] = 'Informe o hábito e o objetivo.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

if (mb_strlen($title) > 120 || mb_strlen($goalTitle) > 120) {
    $_SESSION['flash_error'] = 'Hábito e objetivo devem ter no máximo 120 caracteres.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

$parentGoalIdValue = null;
if ($parentGoalId !== '' && $parentGoalId !== null) {
    $parentGoalIdValue = (int) $parentGoalId;
    if ($parentGoalIdValue <= 0) {
        $_SESSION['flash_error'] = 'Objetivo pai inválido.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
}

try {
    db()->beginTransaction();

    db()->exec(
        'CREATE TABLE IF NOT EXISTS goals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            parent_goal_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_goals_parent FOREIGN KEY (parent_goal_id) REFERENCES goals(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS habits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            goal_id INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_habits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_habits_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    if ($parentGoalIdValue !== null) {
        $parentCheck = db()->prepare('SELECT id FROM goals WHERE id = :id AND user_id = :user_id LIMIT 1');
        $parentCheck->execute([
            'id' => $parentGoalIdValue,
            'user_id' => $userId,
        ]);

        if (!$parentCheck->fetch()) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'O objetivo pai selecionado não existe.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }
    }

    $goalInsert = db()->prepare('INSERT INTO goals (user_id, title, parent_goal_id) VALUES (:user_id, :title, :parent_goal_id)');
    $goalInsert->execute([
        'user_id' => $userId,
        'title' => $goalTitle,
        'parent_goal_id' => $parentGoalIdValue,
    ]);

    $goalId = (int) db()->lastInsertId();

    $habitInsert = db()->prepare('INSERT INTO habits (user_id, goal_id, title, notes) VALUES (:user_id, :goal_id, :title, :notes)');
    $habitInsert->execute([
        'user_id' => $userId,
        'goal_id' => $goalId,
        'title' => $title,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    db()->commit();

    $_SESSION['flash_success'] = 'Hábito criado com sucesso.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível criar o hábito.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
