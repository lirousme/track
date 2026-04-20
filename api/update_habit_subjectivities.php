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
$title = trim((string) ($_POST['title'] ?? ''));
$goalTitle = trim((string) ($_POST['goal_title'] ?? ''));
$parentGoalId = $_POST['parent_goal_id'] ?? '';
$repetitionType = (string) ($_POST['repetition_type'] ?? 'unlimited');
$repetitionLimitInput = trim((string) ($_POST['repetition_limit'] ?? ''));

if ($habitId <= 0) {
    $_SESSION['flash_error'] = 'Hábito inválido.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

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

$repetitionLimit = null;
if ($repetitionType === 'limited') {
    if ($repetitionLimitInput === '') {
        $_SESSION['flash_error'] = 'Informe o número de repetições para um hábito com limite.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    if (!ctype_digit($repetitionLimitInput) || (int) $repetitionLimitInput <= 0) {
        $_SESSION['flash_error'] = 'O limite de repetições deve ser um número inteiro maior que zero.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $repetitionLimit = (int) $repetitionLimitInput;
} elseif ($repetitionType !== 'unlimited') {
    $_SESSION['flash_error'] = 'Tipo de repetição inválido.';
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

    $habitCheck = db()->prepare(
        'SELECT h.id, h.goal_id, h.repetition_count
         FROM habits h
         WHERE h.id = :id AND h.user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $habitCheck->execute([
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    $habit = $habitCheck->fetch();
    if (!$habit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'Hábito não encontrado.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $goalId = (int) $habit['goal_id'];
    $repetitionCount = (int) $habit['repetition_count'];

    if ($repetitionLimit !== null && $repetitionCount > $repetitionLimit) {
        db()->rollBack();
        $_SESSION['flash_error'] = 'O limite de repetições não pode ser menor que o total já marcado.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    if ($parentGoalIdValue !== null) {
        $goalParentCheck = db()->prepare('SELECT id FROM goals WHERE id = :id AND user_id = :user_id LIMIT 1');
        $goalParentCheck->execute([
            'id' => $parentGoalIdValue,
            'user_id' => $userId,
        ]);

        if (!$goalParentCheck->fetch()) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'O objetivo pai selecionado não existe.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        if ($parentGoalIdValue === $goalId) {
            db()->rollBack();
            $_SESSION['flash_error'] = 'Um objetivo não pode ser pai dele mesmo.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }
    }

    $updateGoalStmt = db()->prepare(
        'UPDATE goals
         SET title = :title, parent_goal_id = :parent_goal_id
         WHERE id = :id AND user_id = :user_id'
    );
    $updateGoalStmt->execute([
        'title' => $goalTitle,
        'parent_goal_id' => $parentGoalIdValue,
        'id' => $goalId,
        'user_id' => $userId,
    ]);

    $updateStmt = db()->prepare(
        'UPDATE habits
         SET title = :title, repetition_limit = :repetition_limit
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'title' => $title,
        'repetition_limit' => $repetitionLimit,
        'id' => $habitId,
        'user_id' => $userId,
    ]);

    db()->commit();

    $_SESSION['flash_success'] = 'Hábito atualizado com sucesso.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível atualizar o hábito.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
