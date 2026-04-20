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
$repetitionLimitInput = trim((string) ($_POST['repetition_limit'] ?? ''));
$scheduleCycleKind = (string) ($_POST['schedule_cycle_kind'] ?? 'every_x_days');
$scheduleCycleEveryDaysInput = trim((string) ($_POST['schedule_cycle_every_days'] ?? ''));
$scheduleWeekDaysInput = $_POST['schedule_week_days'] ?? [];
$scheduleMonthDaysInput = trim((string) ($_POST['schedule_month_days'] ?? ''));
$intradayMode = (string) ($_POST['intraday_mode'] ?? 'once');
$intradayEveryValueInput = trim((string) ($_POST['intraday_every_value'] ?? ''));
$intradayEveryUnitInput = (string) ($_POST['intraday_every_unit'] ?? 'minute');
$intradayWindowStart = trim((string) ($_POST['intraday_window_start'] ?? ''));
$intradayWindowEnd = trim((string) ($_POST['intraday_window_end'] ?? ''));

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
$repetitionKind = 'unlimited';
if ($repetitionLimitInput !== '') {
    if (!ctype_digit($repetitionLimitInput) || (int) $repetitionLimitInput <= 0) {
        $_SESSION['flash_error'] = 'O limite de repetições deve ser um número inteiro maior que zero.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    $repetitionLimit = (int) $repetitionLimitInput;
    $repetitionKind = 'count_limit';
}

$scheduleCycleInterval = null;
$scheduleWeekDays = null;
$scheduleMonthDays = null;
if ($scheduleCycleKind === 'every_x_days') {
    if (!ctype_digit($scheduleCycleEveryDaysInput) || (int) $scheduleCycleEveryDaysInput <= 0) {
        $_SESSION['flash_error'] = 'Informe um intervalo válido de dias (inteiro maior que zero).';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    $scheduleCycleInterval = (int) $scheduleCycleEveryDaysInput;
} elseif ($scheduleCycleKind === 'week_days') {
    if (!is_array($scheduleWeekDaysInput)) {
        $scheduleWeekDaysInput = [];
    }
    $allowedWeekDays = ['1','2','3','4','5','6','7'];
    $selected = array_values(array_unique(array_filter(array_map('strval', $scheduleWeekDaysInput), static fn(string $day): bool => in_array($day, $allowedWeekDays, true))));
    if ($selected === []) {
        $_SESSION['flash_error'] = 'Selecione pelo menos um dia da semana.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    sort($selected);
    $scheduleWeekDays = implode(',', $selected);
} elseif ($scheduleCycleKind === 'month_days') {
    if ($scheduleMonthDaysInput === '') {
        $_SESSION['flash_error'] = 'Informe os dias do mês (ex.: 1,15,30).';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $monthDaysParts = preg_split('/\s*,\s*/', $scheduleMonthDaysInput) ?: [];
    $monthDays = [];
    foreach ($monthDaysParts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $value = (int) $part;
        if ($value >= 1 && $value <= 31) {
            $monthDays[] = (string) $value;
        }
    }
    $monthDays = array_values(array_unique($monthDays));
    sort($monthDays);
    if ($monthDays === []) {
        $_SESSION['flash_error'] = 'Os dias do mês devem estar entre 1 e 31.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    $scheduleMonthDays = implode(',', $monthDays);
} else {
    $_SESSION['flash_error'] = 'Configuração de repetição inválida.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

$intradayEveryValue = null;
$intradayEveryUnit = null;
if (!preg_match('/^\d{2}:\d{2}$/', $intradayWindowStart) || !preg_match('/^\d{2}:\d{2}$/', $intradayWindowEnd)) {
    $_SESSION['flash_error'] = 'Informe a janela de horário (início e fim).';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}
if ($intradayWindowStart >= $intradayWindowEnd) {
    $_SESSION['flash_error'] = 'O horário de início precisa ser menor que o horário de fim.';
    header('Location: ' . trackUrl('/index.php?view=track'));
    exit;
}

if ($intradayMode === 'interval') {
    if (!ctype_digit($intradayEveryValueInput) || (int) $intradayEveryValueInput <= 0) {
        $_SESSION['flash_error'] = 'Informe um intervalo válido para repetições no dia.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    if (!in_array($intradayEveryUnitInput, ['minute', 'hour'], true)) {
        $_SESSION['flash_error'] = 'Selecione minuto(s) ou hora(s) para repetição no dia.';
        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }
    $intradayEveryValue = (int) $intradayEveryValueInput;
    $intradayEveryUnit = $intradayEveryUnitInput;
} elseif ($intradayMode !== 'once') {
    $_SESSION['flash_error'] = 'Configuração de repetição no dia inválida.';
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
            schedule_cycle_kind ENUM('every_x_days','week_days','month_days') NOT NULL DEFAULT 'every_x_days',
            schedule_cycle_interval INT UNSIGNED NULL,
            schedule_week_days VARCHAR(32) NULL,
            schedule_month_days VARCHAR(128) NULL,
            intraday_mode ENUM('once','interval') NOT NULL DEFAULT 'once',
            intraday_every_value INT UNSIGNED NULL,
            intraday_every_unit ENUM('minute','hour') NULL,
            intraday_window_start TIME NULL,
            intraday_window_end TIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_habits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_habits_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE
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
    if (!in_array('intraday_window_start', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN intraday_window_start TIME NULL AFTER intraday_every_unit');
    }
    if (!in_array('intraday_window_end', $habitColumns, true)) {
        db()->exec('ALTER TABLE habits ADD COLUMN intraday_window_end TIME NULL AFTER intraday_window_start');
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
            last_check_at,
            schedule_cycle_kind,
            schedule_cycle_interval,
            schedule_week_days,
            schedule_month_days,
            intraday_mode,
            intraday_every_value,
            intraday_every_unit,
            intraday_window_start,
            intraday_window_end
         ) VALUES (
            :user_id,
            :goal_id,
            :title,
            NULL,
            :repetition_kind,
            :repetition_limit,
            0,
            NULL,
            :schedule_cycle_kind,
            :schedule_cycle_interval,
            :schedule_week_days,
            :schedule_month_days,
            :intraday_mode,
            :intraday_every_value,
            :intraday_every_unit,
            :intraday_window_start,
            :intraday_window_end
         )'
    );
    $habitInsert->execute([
        'user_id' => $userId,
        'goal_id' => $goalId,
        'title' => $title,
        'repetition_kind' => $repetitionKind,
        'repetition_limit' => $repetitionLimit,
        'schedule_cycle_kind' => $scheduleCycleKind,
        'schedule_cycle_interval' => $scheduleCycleInterval,
        'schedule_week_days' => $scheduleWeekDays,
        'schedule_month_days' => $scheduleMonthDays,
        'intraday_mode' => $intradayMode,
        'intraday_every_value' => $intradayEveryValue,
        'intraday_every_unit' => $intradayEveryUnit,
        'intraday_window_start' => $intradayWindowStart . ':00',
        'intraday_window_end' => $intradayWindowEnd . ':00',
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
