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
$repetitionEveryValueInput = trim((string) ($_POST['repetition_every_value'] ?? ''));
$repetitionEveryUnitInput = (string) ($_POST['repetition_every_unit'] ?? '');
$repetitionStartAtInput = trim((string) ($_POST['repetition_start_at'] ?? ''));
$repetitionEndAtInput = trim((string) ($_POST['repetition_end_at'] ?? ''));

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

$repetitionKind = 'unlimited';
$repetitionLimit = null;
$repetitionEveryValue = null;
$repetitionEveryUnit = null;
$repetitionStartAt = null;
$repetitionEndAt = null;
$nextDueAt = null;
if ($repetitionType === 'limited') {
    $repetitionKind = 'count_limit';
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
} elseif ($repetitionType === 'interval') {
    $repetitionKind = 'interval';

    if (!ctype_digit($repetitionEveryValueInput) || (int) $repetitionEveryValueInput <= 0) {
        $_SESSION['flash_error'] = 'Informe um intervalo válido (valor inteiro maior que zero).';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    if (!in_array($repetitionEveryUnitInput, ['minute', 'hour', 'day'], true)) {
        $_SESSION['flash_error'] = 'Selecione a unidade do intervalo (minuto, hora ou dia).';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    if ($repetitionStartAtInput === '') {
        $_SESSION['flash_error'] = 'Informe a data e hora de início para repetição por intervalo.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $startAtDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $repetitionStartAtInput);
    $startAtErrors = DateTimeImmutable::getLastErrors();
    if ($startAtDate === false || (($startAtErrors['warning_count'] ?? 0) > 0) || (($startAtErrors['error_count'] ?? 0) > 0)) {
        $_SESSION['flash_error'] = 'A data e hora de início são inválidas.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $endAtDate = null;
    if ($repetitionEndAtInput !== '') {
        $endAtDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $repetitionEndAtInput);
        $endAtErrors = DateTimeImmutable::getLastErrors();
        if ($endAtDate === false || (($endAtErrors['warning_count'] ?? 0) > 0) || (($endAtErrors['error_count'] ?? 0) > 0)) {
            $_SESSION['flash_error'] = 'A data e hora de término são inválidas.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }

        if ($endAtDate <= $startAtDate) {
            $_SESSION['flash_error'] = 'A data e hora de término devem ser posteriores ao início.';
            header('Location: ' . trackUrl('/index.php?view=track'));
            exit;
        }
    }

    $repetitionEveryValue = (int) $repetitionEveryValueInput;
    $repetitionEveryUnit = $repetitionEveryUnitInput;
    $repetitionStartAt = $startAtDate->format('Y-m-d H:i:s');
    $repetitionEndAt = $endAtDate?->format('Y-m-d H:i:s');
    $nextDueAt = $repetitionStartAt;
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

    $habitColumns = db()->query('SHOW COLUMNS FROM habits')->fetchAll(PDO::FETCH_COLUMN, 0);
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

    if ($repetitionKind === 'count_limit' && $repetitionLimit !== null && $repetitionCount > $repetitionLimit) {
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
         SET
            title = :title,
            repetition_kind = :repetition_kind,
            repetition_limit = :repetition_limit,
            repetition_every_value = :repetition_every_value,
            repetition_every_unit = :repetition_every_unit,
            repetition_start_at = :repetition_start_at,
            repetition_end_at = :repetition_end_at,
            next_due_at = :next_due_at
         WHERE id = :id AND user_id = :user_id'
    );
    $updateStmt->execute([
        'title' => $title,
        'repetition_kind' => $repetitionKind,
        'repetition_limit' => $repetitionLimit,
        'repetition_every_value' => $repetitionEveryValue,
        'repetition_every_unit' => $repetitionEveryUnit,
        'repetition_start_at' => $repetitionStartAt,
        'repetition_end_at' => $repetitionEndAt,
        'next_due_at' => $nextDueAt,
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
