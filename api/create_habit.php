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
$parentGoalId = $_POST['parent_goal_id'] ?? '';
$repetitionType = (string) ($_POST['repetition_type'] ?? 'unlimited');
$repetitionLimitInput = trim((string) ($_POST['repetition_limit'] ?? ''));
$repetitionEveryValueInput = trim((string) ($_POST['repetition_every_value'] ?? ''));
$repetitionEveryUnitInput = (string) ($_POST['repetition_every_unit'] ?? '');
$repetitionStartAtInput = trim((string) ($_POST['repetition_start_at'] ?? ''));
$repetitionEndAtInput = trim((string) ($_POST['repetition_end_at'] ?? ''));

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
        "CREATE TABLE IF NOT EXISTS habits (
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_habits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_habits_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
            CONSTRAINT chk_habits_interval_value CHECK (
                repetition_kind <> 'interval' OR (repetition_every_value IS NOT NULL AND repetition_every_value > 0)
            ),
            CONSTRAINT chk_habits_interval_unit CHECK (
                repetition_kind <> 'interval' OR repetition_every_unit IS NOT NULL
            )
        ) ENGINE=InnoDB"
    );

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

    $habitInsert = db()->prepare(
        'INSERT INTO habits (
            user_id,
            goal_id,
            title,
            subjectivities,
            repetition_kind,
            repetition_limit,
            repetition_count,
            repetition_every_value,
            repetition_every_unit,
            repetition_start_at,
            repetition_end_at,
            next_due_at,
            last_check_at
         ) VALUES (
            :user_id,
            :goal_id,
            :title,
            NULL,
            :repetition_kind,
            :repetition_limit,
            0,
            :repetition_every_value,
            :repetition_every_unit,
            :repetition_start_at,
            :repetition_end_at,
            :next_due_at,
            NULL
         )'
    );
    $habitInsert->execute([
        'user_id' => $userId,
        'goal_id' => $goalId,
        'title' => $title,
        'repetition_kind' => $repetitionKind,
        'repetition_limit' => $repetitionLimit,
        'repetition_every_value' => $repetitionEveryValue,
        'repetition_every_unit' => $repetitionEveryUnit,
        'repetition_start_at' => $repetitionStartAt,
        'repetition_end_at' => $repetitionEndAt,
        'next_due_at' => $nextDueAt,
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
