<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

require_once __DIR__ . '/../config/db.php';

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$habits = [];
$goals = [];
$loadError = null;

try {
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

    $goalStmt = db()->prepare('SELECT id, title, parent_goal_id FROM goals WHERE user_id = :user_id ORDER BY title ASC');
    $goalStmt->execute(['user_id' => $userId]);
    $goals = $goalStmt->fetchAll();

    if ($goals === []) {
        $defaultGoalStmt = db()->prepare('INSERT INTO goals (user_id, title, parent_goal_id) VALUES (:user_id, :title, NULL)');
        $defaultGoalStmt->execute([
            'user_id' => $userId,
            'title' => 'Objetivo principal',
        ]);

        $goalStmt->execute(['user_id' => $userId]);
        $goals = $goalStmt->fetchAll();
    }

    $habitStmt = db()->prepare(
        'SELECT
            h.id,
            h.title,
            h.goal_id,
            h.repetition_kind,
            h.repetition_limit,
            h.repetition_count,
            h.schedule_cycle_kind,
            h.schedule_cycle_interval,
            h.schedule_week_days,
            h.schedule_month_days,
            h.intraday_mode,
            h.intraday_every_value,
            h.intraday_every_unit,
            g.parent_goal_id,
            g.title AS goal_title,
            parent.title AS parent_goal_title
        FROM habits h
        INNER JOIN goals g ON g.id = h.goal_id
        LEFT JOIN goals parent ON parent.id = g.parent_goal_id
        WHERE h.user_id = :user_id
        ORDER BY h.created_at DESC'
    );
    $habitStmt->execute(['user_id' => $userId]);
    $habits = $habitStmt->fetchAll();
} catch (Throwable $exception) {
    $loadError = 'Não foi possível carregar os hábitos. Verifique sua configuração de banco de dados.';
}

?>
<section class="w-full rounded-2xl border border-slate-800 bg-slate-900/70 p-8 shadow-2xl shadow-slate-950">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">Track</h1>
            <p class="text-sm text-slate-400">Bem-vindo, <?= htmlspecialchars((string) ($_SESSION['user']['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" id="openHabitModal" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-slate-900 hover:brightness-110">
                + Novo hábito
            </button>
            <a href="<?= htmlspecialchars(trackUrl('/api/logout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                Sair
            </a>
        </div>
    </div>

    <?php if (!empty($flashError)): ?>
        <div class="mb-4 rounded-lg border border-rose-400/50 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            <?= htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashSuccess)): ?>
        <div class="mb-4 rounded-lg border border-emerald-400/50 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            <?= htmlspecialchars((string) $flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError !== null): ?>
        <div class="rounded-xl border border-red-900/70 bg-red-950/50 p-4 text-sm text-red-200">
            <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php else: ?>
        <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-6 text-slate-300">
            <h2 class="mb-4 text-lg font-semibold text-slate-100">Hábitos</h2>

            <?php if ($habits === []): ?>
                <p class="text-sm text-slate-400">Você ainda não criou hábitos. Clique em <span class="font-semibold text-brand">+ Novo hábito</span> para começar.</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($habits as $habit): ?>
                        <li class="rounded-lg border border-slate-800 bg-slate-900/70 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-semibold text-slate-100"><?= htmlspecialchars((string) $habit['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="mt-1 text-sm text-slate-300">
                                        Objetivo: <?= htmlspecialchars((string) $habit['goal_title'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($habit['parent_goal_title'])): ?>
                                            <span class="text-slate-400">(subobjetivo de <?= htmlspecialchars((string) $habit['parent_goal_title'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mt-3 text-xs text-slate-400">
                                        Repetições: <span class="font-semibold text-slate-200"><?= (int) $habit['repetition_count']; ?></span>
                                        <?php if ($habit['repetition_limit'] !== null): ?>
                                            / <span class="font-semibold text-slate-200"><?= (int) $habit['repetition_limit']; ?></span>
                                        <?php else: ?>
                                            <span class="text-emerald-300">(sem limite)</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        Agenda:
                                        <?php
                                            $cycleKind = (string) ($habit['schedule_cycle_kind'] ?? 'every_x_days');
                                            if ($cycleKind === 'week_days') {
                                                echo ' dias da semana: ' . htmlspecialchars((string) ($habit['schedule_week_days'] ?? '-'), ENT_QUOTES, 'UTF-8');
                                            } elseif ($cycleKind === 'month_days') {
                                                echo ' dias do mês: ' . htmlspecialchars((string) ($habit['schedule_month_days'] ?? '-'), ENT_QUOTES, 'UTF-8');
                                            } else {
                                                echo ' a cada ' . (int) ($habit['schedule_cycle_interval'] ?? 1) . ' dia(s)';
                                            }
                                        ?>
                                        |
                                        <?php if (($habit['intraday_mode'] ?? 'once') === 'interval'): ?>
                                            no dia: a cada <?= (int) ($habit['intraday_every_value'] ?? 0); ?> <?= (($habit['intraday_every_unit'] ?? 'minute') === 'hour') ? 'hora(s)' : 'minuto(s)'; ?>
                                        <?php else: ?>
                                            no dia: uma vez
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div class="flex flex-col items-stretch gap-2">
                                    <button
                                        type="button"
                                        class="openSubjectivitiesModal rounded-lg border border-slate-700 bg-slate-800/70 px-3 py-2 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                                        data-habit-id="<?= (int) $habit['id']; ?>"
                                        data-habit-title="<?= htmlspecialchars((string) $habit['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-goal-title="<?= htmlspecialchars((string) $habit['goal_title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-parent-goal-id="<?= $habit['parent_goal_id'] !== null ? (int) $habit['parent_goal_id'] : ''; ?>"
                                        data-repetition-limit="<?= $habit['repetition_limit'] !== null ? (int) $habit['repetition_limit'] : ''; ?>"
                                        data-schedule-cycle-kind="<?= htmlspecialchars((string) ($habit['schedule_cycle_kind'] ?? 'every_x_days'), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-schedule-cycle-interval="<?= $habit['schedule_cycle_interval'] !== null ? (int) $habit['schedule_cycle_interval'] : ''; ?>"
                                        data-schedule-week-days="<?= htmlspecialchars((string) ($habit['schedule_week_days'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-schedule-month-days="<?= htmlspecialchars((string) ($habit['schedule_month_days'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-intraday-mode="<?= htmlspecialchars((string) ($habit['intraday_mode'] ?? 'once'), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-intraday-every-value="<?= $habit['intraday_every_value'] !== null ? (int) $habit['intraday_every_value'] : ''; ?>"
                                        data-intraday-every-unit="<?= htmlspecialchars((string) ($habit['intraday_every_unit'] ?? 'minute'), ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-label="Configurar hábito"
                                    >
                                        ⚙
                                    </button>

                                    <form action="<?= htmlspecialchars(trackUrl('/api/check_habit.php'), ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                                        <input type="hidden" name="habit_id" value="<?= (int) $habit['id']; ?>">
                                        <button type="submit" class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-xs font-semibold text-emerald-200 hover:bg-emerald-500/20">
                                            ✔ Check
                                        </button>
                                    </form>

                                    <form action="<?= htmlspecialchars(trackUrl('/api/uncheck_habit.php'), ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                                        <input type="hidden" name="habit_id" value="<?= (int) $habit['id']; ?>">
                                        <button type="submit" class="w-full rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-200 hover:bg-amber-500/20">
                                            ↩ Uncheck
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<div id="habitModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
    <div class="w-full max-w-lg rounded-xl border border-slate-700 bg-slate-900 p-6 shadow-2xl shadow-slate-950">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-slate-100">Criar novo hábito</h3>
            <button type="button" id="closeHabitModal" class="rounded-md px-2 py-1 text-slate-300 hover:bg-slate-800">✕</button>
        </div>

        <form action="<?= htmlspecialchars(trackUrl('/api/create_habit.php'), ENT_QUOTES, 'UTF-8'); ?>" method="POST" class="space-y-4">
            <div>
                <label for="title" class="mb-1 block text-sm text-slate-300">Hábito</label>
                <input id="title" name="title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>
            <div>
                <label for="goal_title" class="mb-1 block text-sm text-slate-300">Objetivo</label>
                <input id="goal_title" name="goal_title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>
            <div>
                <label for="parent_goal_id" class="mb-1 block text-sm text-slate-300">Objetivo pai (opcional)</label>
                <select id="parent_goal_id" name="parent_goal_id" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="">Nenhum</option>
                    <?php foreach ($goals as $goal): ?>
                        <option value="<?= (int) $goal['id']; ?>"><?= htmlspecialchars((string) $goal['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="repetition_limit" class="mb-1 block text-sm text-slate-300">Limite total de repetições (opcional)</label>
                <input id="repetition_limit" name="repetition_limit" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>
            <div>
                <label for="schedule_cycle_kind" class="mb-1 block text-sm text-slate-300">Quando repetir</label>
                <select id="schedule_cycle_kind" name="schedule_cycle_kind" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="every_x_days">De X em X dias</option>
                    <option value="week_days">Dias específicos da semana</option>
                    <option value="month_days">Dias específicos do mês</option>
                </select>
            </div>
            <div id="cycleEveryDaysWrap">
                <label for="schedule_cycle_every_days" class="mb-1 block text-sm text-slate-300">A cada quantos dias</label>
                <input id="schedule_cycle_every_days" name="schedule_cycle_every_days" type="number" min="1" step="1" value="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>
            <div id="cycleWeekDaysWrap" class="hidden rounded-lg border border-slate-800 bg-slate-950/40 p-3 text-sm text-slate-300">
                <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $dayValue => $dayLabel): ?>
                    <label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" name="schedule_week_days[]" value="<?= $dayValue; ?>"> <?= $dayLabel; ?></label>
                <?php endforeach; ?>
            </div>
            <div id="cycleMonthDaysWrap" class="hidden">
                <label for="schedule_month_days" class="mb-1 block text-sm text-slate-300">Dias do mês</label>
                <input id="schedule_month_days" name="schedule_month_days" type="text" placeholder="Ex.: 1,15,30" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>
            <div>
                <label for="intraday_mode" class="mb-1 block text-sm text-slate-300">No dia escolhido</label>
                <select id="intraday_mode" name="intraday_mode" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="once">Uma única vez</option>
                    <option value="interval">De X em X minutos/horas</option>
                </select>
            </div>
            <div id="intradayIntervalWrap" class="hidden grid grid-cols-2 gap-3">
                <input id="intraday_every_value" name="intraday_every_value" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none" placeholder="Valor">
                <select id="intraday_every_unit" name="intraday_every_unit" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="minute">Minuto(s)</option>
                    <option value="hour">Hora(s)</option>
                </select>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelHabitModal" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">Cancelar</button>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-slate-900 hover:brightness-110">Salvar hábito</button>
            </div>
        </form>
    </div>
</div>

<div id="subjectivitiesModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
    <div class="w-full max-w-lg rounded-xl border border-slate-700 bg-slate-900 p-6 shadow-2xl shadow-slate-950">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-slate-100">Editar hábito</h3>
            <button type="button" id="closeSubjectivitiesModal" class="rounded-md px-2 py-1 text-slate-300 hover:bg-slate-800">✕</button>
        </div>
        <p id="subjectivitiesHabitTitle" class="mb-3 text-sm text-slate-400"></p>

        <form action="<?= htmlspecialchars(trackUrl('/api/update_habit_subjectivities.php'), ENT_QUOTES, 'UTF-8'); ?>" method="POST" class="space-y-4">
            <input type="hidden" name="habit_id" id="subjectivitiesHabitId">
            <input id="edit_habit_title" name="title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            <input id="edit_goal_title" name="goal_title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            <select id="edit_parent_goal_id" name="parent_goal_id" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                <option value="">Nenhum</option>
                <?php foreach ($goals as $goal): ?>
                    <option value="<?= (int) $goal['id']; ?>"><?= htmlspecialchars((string) $goal['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <input id="edit_repetition_limit" name="repetition_limit" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none" placeholder="Limite total (opcional)">
            <select id="edit_schedule_cycle_kind" name="schedule_cycle_kind" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                <option value="every_x_days">De X em X dias</option><option value="week_days">Dias da semana</option><option value="month_days">Dias do mês</option>
            </select>
            <div id="editCycleEveryDaysWrap">
                <input id="edit_schedule_cycle_every_days" name="schedule_cycle_every_days" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none" placeholder="A cada X dias">
            </div>
            <div id="editCycleMonthDaysWrap" class="hidden">
                <input id="edit_schedule_month_days" name="schedule_month_days" type="text" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none" placeholder="Dias do mês (ex.: 1,15)">
            </div>
            <div id="editCycleWeekDaysWrap" class="hidden rounded-lg border border-slate-800 bg-slate-950/40 p-3 text-sm text-slate-300">
                <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $dayValue => $dayLabel): ?>
                    <label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" name="schedule_week_days[]" value="<?= $dayValue; ?>" class="editWeekday"> <?= $dayLabel; ?></label>
                <?php endforeach; ?>
            </div>
            <select id="edit_intraday_mode" name="intraday_mode" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                <option value="once">Uma única vez</option><option value="interval">De X em X minutos/horas</option>
            </select>
            <div id="editIntradayIntervalWrap" class="hidden grid grid-cols-2 gap-3">
                <input id="edit_intraday_every_value" name="intraday_every_value" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none" placeholder="Valor">
                <select id="edit_intraday_every_unit" name="intraday_every_unit" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none"><option value="minute">Minuto(s)</option><option value="hour">Hora(s)</option></select>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelSubjectivitiesModal" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">Cancelar</button>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-slate-900 hover:brightness-110">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('habitModal');
const openButton = document.getElementById('openHabitModal');
const closeButton = document.getElementById('closeHabitModal');
const cancelButton = document.getElementById('cancelHabitModal');
const cycleKind = document.getElementById('schedule_cycle_kind');
const cycleEveryDaysWrap = document.getElementById('cycleEveryDaysWrap');
const cycleWeekDaysWrap = document.getElementById('cycleWeekDaysWrap');
const cycleMonthDaysWrap = document.getElementById('cycleMonthDaysWrap');
const intradayMode = document.getElementById('intraday_mode');
const intradayIntervalWrap = document.getElementById('intradayIntervalWrap');

const toggleCycle = () => {
  const value = cycleKind?.value;
  cycleEveryDaysWrap?.classList.toggle('hidden', value !== 'every_x_days');
  cycleWeekDaysWrap?.classList.toggle('hidden', value !== 'week_days');
  cycleMonthDaysWrap?.classList.toggle('hidden', value !== 'month_days');
};
const toggleIntraday = () => intradayIntervalWrap?.classList.toggle('hidden', intradayMode?.value !== 'interval');

openButton?.addEventListener('click', ()=>{ modal.classList.remove('hidden'); modal.classList.add('flex'); });
closeButton?.addEventListener('click', ()=>{ modal.classList.add('hidden'); modal.classList.remove('flex'); });
cancelButton?.addEventListener('click', ()=>{ modal.classList.add('hidden'); modal.classList.remove('flex'); });
cycleKind?.addEventListener('change', toggleCycle);
intradayMode?.addEventListener('change', toggleIntraday);
toggleCycle(); toggleIntraday();

const subjectivitiesModal = document.getElementById('subjectivitiesModal');
const editCycleKind = document.getElementById('edit_schedule_cycle_kind');
const editCycleEveryDaysWrap = document.getElementById('editCycleEveryDaysWrap');
const editCycleWeekDaysWrap = document.getElementById('editCycleWeekDaysWrap');
const editCycleMonthDaysWrap = document.getElementById('editCycleMonthDaysWrap');
const editIntradayMode = document.getElementById('edit_intraday_mode');
const editIntradayIntervalWrap = document.getElementById('editIntradayIntervalWrap');

const toggleEditCycle = () => {
  const value = editCycleKind?.value;
  editCycleEveryDaysWrap?.classList.toggle('hidden', value !== 'every_x_days');
  editCycleWeekDaysWrap?.classList.toggle('hidden', value !== 'week_days');
  editCycleMonthDaysWrap?.classList.toggle('hidden', value !== 'month_days');
};

const toggleEditIntraday = () => {
  editIntradayIntervalWrap?.classList.toggle('hidden', editIntradayMode?.value !== 'interval');
};

editCycleKind?.addEventListener('change', toggleEditCycle);
editIntradayMode?.addEventListener('change', toggleEditIntraday);

document.querySelectorAll('.openSubjectivitiesModal').forEach((button) => {
  button.addEventListener('click', () => {
    document.getElementById('subjectivitiesHabitId').value = button.dataset.habitId ?? '';
    document.getElementById('edit_habit_title').value = button.dataset.habitTitle ?? '';
    document.getElementById('edit_goal_title').value = button.dataset.goalTitle ?? '';
    document.getElementById('edit_parent_goal_id').value = button.dataset.parentGoalId ?? '';
    document.getElementById('edit_repetition_limit').value = button.dataset.repetitionLimit ?? '';
    document.getElementById('edit_schedule_cycle_kind').value = button.dataset.scheduleCycleKind ?? 'every_x_days';
    document.getElementById('edit_schedule_cycle_every_days').value = button.dataset.scheduleCycleInterval ?? '';
    document.getElementById('edit_schedule_month_days').value = button.dataset.scheduleMonthDays ?? '';
    document.getElementById('edit_intraday_mode').value = button.dataset.intradayMode ?? 'once';
    document.getElementById('edit_intraday_every_value').value = button.dataset.intradayEveryValue ?? '';
    document.getElementById('edit_intraday_every_unit').value = button.dataset.intradayEveryUnit ?? 'minute';

    const weekdays = (button.dataset.scheduleWeekDays ?? '').split(',');
    document.querySelectorAll('.editWeekday').forEach((checkbox) => {
      checkbox.checked = weekdays.includes(checkbox.value);
    });

    document.getElementById('subjectivitiesHabitTitle').textContent = `Hábito: ${button.dataset.habitTitle ?? ''}`;
    toggleEditCycle();
    toggleEditIntraday();
    subjectivitiesModal?.classList.remove('hidden');
    subjectivitiesModal?.classList.add('flex');
  });
});
toggleEditCycle();
toggleEditIntraday();
document.getElementById('closeSubjectivitiesModal')?.addEventListener('click', ()=>{subjectivitiesModal?.classList.add('hidden');subjectivitiesModal?.classList.remove('flex');});
document.getElementById('cancelSubjectivitiesModal')?.addEventListener('click', ()=>{subjectivitiesModal?.classList.add('hidden');subjectivitiesModal?.classList.remove('flex');});
</script>
