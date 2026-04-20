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
            h.repetition_every_value,
            h.repetition_every_unit,
            h.repetition_start_at,
            h.repetition_end_at,
            h.next_due_at,
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
                                        <?php if (($habit['repetition_kind'] ?? 'unlimited') === 'count_limit' && $habit['repetition_limit'] !== null): ?>
                                            / <span class="font-semibold text-slate-200"><?= (int) $habit['repetition_limit']; ?></span>
                                        <?php elseif (($habit['repetition_kind'] ?? 'unlimited') === 'interval'): ?>
                                            <span class="text-amber-300">
                                                (a cada <?= (int) ($habit['repetition_every_value'] ?? 0); ?>
                                                <?php
                                                    $unit = (string) ($habit['repetition_every_unit'] ?? '');
                                                    echo $unit === 'minute' ? 'minuto(s)' : ($unit === 'hour' ? 'hora(s)' : 'dia(s)');
                                                ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-emerald-300">(ilimitado)</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (($habit['repetition_kind'] ?? 'unlimited') === 'interval'): ?>
                                        <p class="mt-1 text-xs text-slate-400">
                                            Início: <span class="text-slate-200"><?= htmlspecialchars((string) ($habit['repetition_start_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($habit['repetition_end_at'])): ?>
                                                | Fim: <span class="text-slate-200"><?= htmlspecialchars((string) $habit['repetition_end_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($habit['next_due_at'])): ?>
                                                | Próximo check: <span class="text-brand"><?= htmlspecialchars((string) $habit['next_due_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="openSubjectivitiesModal rounded-lg border border-slate-700 bg-slate-800/70 px-3 py-2 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                                        data-habit-id="<?= (int) $habit['id']; ?>"
                                        data-habit-title="<?= htmlspecialchars((string) $habit['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-goal-title="<?= htmlspecialchars((string) $habit['goal_title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-parent-goal-id="<?= $habit['parent_goal_id'] !== null ? (int) $habit['parent_goal_id'] : ''; ?>"
                                        data-repetition-kind="<?= htmlspecialchars((string) ($habit['repetition_kind'] ?? 'unlimited'), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-repetition-limit="<?= $habit['repetition_limit'] !== null ? (int) $habit['repetition_limit'] : ''; ?>"
                                        data-repetition-every-value="<?= $habit['repetition_every_value'] !== null ? (int) $habit['repetition_every_value'] : ''; ?>"
                                        data-repetition-every-unit="<?= htmlspecialchars((string) ($habit['repetition_every_unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-repetition-start-at="<?= htmlspecialchars((string) ($habit['repetition_start_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-repetition-end-at="<?= htmlspecialchars((string) ($habit['repetition_end_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
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
                <label for="repetition_type" class="mb-1 block text-sm text-slate-300">Repetição</label>
                <select id="repetition_type" name="repetition_type" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="unlimited">Ilimitada</option>
                    <option value="limited">Com limite</option>
                    <option value="interval">Por intervalo (data/hora)</option>
                </select>
            </div>

            <div id="repetitionLimitWrapper" class="hidden">
                <label for="repetition_limit" class="mb-1 block text-sm text-slate-300">Limite de repetições</label>
                <input id="repetition_limit" name="repetition_limit" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>

            <div id="repetitionIntervalWrapper" class="hidden space-y-3 rounded-lg border border-slate-800 bg-slate-950/40 p-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="repetition_every_value" class="mb-1 block text-sm text-slate-300">A cada</label>
                        <input id="repetition_every_value" name="repetition_every_value" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    </div>
                    <div>
                        <label for="repetition_every_unit" class="mb-1 block text-sm text-slate-300">Unidade</label>
                        <select id="repetition_every_unit" name="repetition_every_unit" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                            <option value="minute">Minuto(s)</option>
                            <option value="hour">Hora(s)</option>
                            <option value="day">Dia(s)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="repetition_start_at" class="mb-1 block text-sm text-slate-300">Início</label>
                    <input id="repetition_start_at" name="repetition_start_at" type="datetime-local" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                </div>
                <div>
                    <label for="repetition_end_at" class="mb-1 block text-sm text-slate-300">Fim (opcional)</label>
                    <input id="repetition_end_at" name="repetition_end_at" type="datetime-local" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                </div>
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

            <div>
                <label for="edit_habit_title" class="mb-1 block text-sm text-slate-300">Hábito</label>
                <input id="edit_habit_title" name="title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>

            <div>
                <label for="edit_goal_title" class="mb-1 block text-sm text-slate-300">Objetivo</label>
                <input id="edit_goal_title" name="goal_title" type="text" maxlength="120" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>

            <div>
                <label for="edit_parent_goal_id" class="mb-1 block text-sm text-slate-300">Objetivo pai (opcional)</label>
                <select id="edit_parent_goal_id" name="parent_goal_id" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="">Nenhum</option>
                    <?php foreach ($goals as $goal): ?>
                        <option value="<?= (int) $goal['id']; ?>"><?= htmlspecialchars((string) $goal['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="edit_repetition_type" class="mb-1 block text-sm text-slate-300">Repetição</label>
                <select id="edit_repetition_type" name="repetition_type" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    <option value="unlimited">Ilimitada</option>
                    <option value="limited">Com limite</option>
                    <option value="interval">Por intervalo (data/hora)</option>
                </select>
            </div>

            <div id="editRepetitionLimitWrapper" class="hidden">
                <label for="edit_repetition_limit" class="mb-1 block text-sm text-slate-300">Limite de repetições</label>
                <input id="edit_repetition_limit" name="repetition_limit" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
            </div>

            <div id="editRepetitionIntervalWrapper" class="hidden space-y-3 rounded-lg border border-slate-800 bg-slate-950/40 p-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="edit_repetition_every_value" class="mb-1 block text-sm text-slate-300">A cada</label>
                        <input id="edit_repetition_every_value" name="repetition_every_value" type="number" min="1" step="1" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                    </div>
                    <div>
                        <label for="edit_repetition_every_unit" class="mb-1 block text-sm text-slate-300">Unidade</label>
                        <select id="edit_repetition_every_unit" name="repetition_every_unit" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                            <option value="minute">Minuto(s)</option>
                            <option value="hour">Hora(s)</option>
                            <option value="day">Dia(s)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="edit_repetition_start_at" class="mb-1 block text-sm text-slate-300">Início</label>
                    <input id="edit_repetition_start_at" name="repetition_start_at" type="datetime-local" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                </div>
                <div>
                    <label for="edit_repetition_end_at" class="mb-1 block text-sm text-slate-300">Fim (opcional)</label>
                    <input id="edit_repetition_end_at" name="repetition_end_at" type="datetime-local" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
                </div>
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
    const repetitionType = document.getElementById('repetition_type');
    const repetitionLimitWrapper = document.getElementById('repetitionLimitWrapper');
    const repetitionLimitInput = document.getElementById('repetition_limit');
    const repetitionIntervalWrapper = document.getElementById('repetitionIntervalWrapper');
    const repetitionEveryValueInput = document.getElementById('repetition_every_value');
    const repetitionEveryUnitInput = document.getElementById('repetition_every_unit');
    const repetitionStartAtInput = document.getElementById('repetition_start_at');
    const repetitionEndAtInput = document.getElementById('repetition_end_at');
    const subjectivitiesModal = document.getElementById('subjectivitiesModal');
    const openSubjectivitiesButtons = document.querySelectorAll('.openSubjectivitiesModal');
    const closeSubjectivitiesModalButton = document.getElementById('closeSubjectivitiesModal');
    const cancelSubjectivitiesModalButton = document.getElementById('cancelSubjectivitiesModal');
    const subjectivitiesHabitIdInput = document.getElementById('subjectivitiesHabitId');
    const editHabitTitleInput = document.getElementById('edit_habit_title');
    const editGoalTitleInput = document.getElementById('edit_goal_title');
    const editParentGoalIdInput = document.getElementById('edit_parent_goal_id');
    const editRepetitionTypeInput = document.getElementById('edit_repetition_type');
    const editRepetitionLimitWrapper = document.getElementById('editRepetitionLimitWrapper');
    const editRepetitionLimitInput = document.getElementById('edit_repetition_limit');
    const editRepetitionIntervalWrapper = document.getElementById('editRepetitionIntervalWrapper');
    const editRepetitionEveryValueInput = document.getElementById('edit_repetition_every_value');
    const editRepetitionEveryUnitInput = document.getElementById('edit_repetition_every_unit');
    const editRepetitionStartAtInput = document.getElementById('edit_repetition_start_at');
    const editRepetitionEndAtInput = document.getElementById('edit_repetition_end_at');
    const subjectivitiesHabitTitle = document.getElementById('subjectivitiesHabitTitle');

    const dbDateToLocalInput = (value) => value ? String(value).replace(' ', 'T').slice(0, 16) : '';

    const openModal = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openButton?.addEventListener('click', openModal);
    closeButton?.addEventListener('click', closeModal);
    cancelButton?.addEventListener('click', closeModal);

    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    const toggleRepetitionLimit = () => {
        const isLimited = repetitionType?.value === 'limited';
        const isInterval = repetitionType?.value === 'interval';
        repetitionLimitWrapper?.classList.toggle('hidden', !isLimited);
        repetitionIntervalWrapper?.classList.toggle('hidden', !isInterval);
        if (repetitionLimitInput) {
            repetitionLimitInput.required = isLimited;
            if (!isLimited) {
                repetitionLimitInput.value = '';
            }
        }
        if (repetitionEveryValueInput) {
            repetitionEveryValueInput.required = isInterval;
            if (!isInterval) repetitionEveryValueInput.value = '';
        }
        if (repetitionEveryUnitInput) {
            repetitionEveryUnitInput.required = isInterval;
            if (!isInterval) repetitionEveryUnitInput.value = 'minute';
        }
        if (repetitionStartAtInput) {
            repetitionStartAtInput.required = isInterval;
            if (!isInterval) repetitionStartAtInput.value = '';
        }
        if (repetitionEndAtInput && !isInterval) {
            repetitionEndAtInput.value = '';
        }
    };

    repetitionType?.addEventListener('change', toggleRepetitionLimit);
    toggleRepetitionLimit();

    const toggleEditRepetitionLimit = () => {
        const isLimited = editRepetitionTypeInput?.value === 'limited';
        const isInterval = editRepetitionTypeInput?.value === 'interval';
        editRepetitionLimitWrapper?.classList.toggle('hidden', !isLimited);
        editRepetitionIntervalWrapper?.classList.toggle('hidden', !isInterval);
        if (editRepetitionLimitInput) {
            editRepetitionLimitInput.required = isLimited;
            if (!isLimited) {
                editRepetitionLimitInput.value = '';
            }
        }
        if (editRepetitionEveryValueInput) {
            editRepetitionEveryValueInput.required = isInterval;
            if (!isInterval) editRepetitionEveryValueInput.value = '';
        }
        if (editRepetitionEveryUnitInput) {
            editRepetitionEveryUnitInput.required = isInterval;
            if (!isInterval) editRepetitionEveryUnitInput.value = 'minute';
        }
        if (editRepetitionStartAtInput) {
            editRepetitionStartAtInput.required = isInterval;
            if (!isInterval) editRepetitionStartAtInput.value = '';
        }
        if (editRepetitionEndAtInput && !isInterval) {
            editRepetitionEndAtInput.value = '';
        }
    };

    const openSubjectivitiesModal = (
        habitId,
        habitTitle,
        goalTitle,
        parentGoalId,
        repetitionKind,
        repetitionLimit,
        repetitionEveryValue,
        repetitionEveryUnit,
        repetitionStartAt,
        repetitionEndAt
    ) => {
        if (subjectivitiesHabitIdInput) {
            subjectivitiesHabitIdInput.value = String(habitId);
        }
        if (editHabitTitleInput) {
            editHabitTitleInput.value = habitTitle ?? '';
        }
        if (editGoalTitleInput) {
            editGoalTitleInput.value = goalTitle ?? '';
        }
        if (editParentGoalIdInput) {
            editParentGoalIdInput.value = parentGoalId ?? '';
        }
        if (editRepetitionTypeInput) {
            if (repetitionKind === 'interval') {
                editRepetitionTypeInput.value = 'interval';
            } else if (repetitionKind === 'count_limit') {
                editRepetitionTypeInput.value = 'limited';
            } else {
                editRepetitionTypeInput.value = 'unlimited';
            }
        }
        if (editRepetitionLimitInput) {
            editRepetitionLimitInput.value = repetitionLimit ?? '';
        }
        if (editRepetitionEveryValueInput) {
            editRepetitionEveryValueInput.value = repetitionEveryValue ?? '';
        }
        if (editRepetitionEveryUnitInput) {
            editRepetitionEveryUnitInput.value = repetitionEveryUnit ?? 'minute';
        }
        if (editRepetitionStartAtInput) {
            editRepetitionStartAtInput.value = dbDateToLocalInput(repetitionStartAt);
        }
        if (editRepetitionEndAtInput) {
            editRepetitionEndAtInput.value = dbDateToLocalInput(repetitionEndAt);
        }
        if (subjectivitiesHabitTitle) {
            subjectivitiesHabitTitle.textContent = `Hábito: ${habitTitle}`;
        }
        toggleEditRepetitionLimit();

        subjectivitiesModal?.classList.remove('hidden');
        subjectivitiesModal?.classList.add('flex');
    };

    const closeSubjectivitiesModal = () => {
        subjectivitiesModal?.classList.add('hidden');
        subjectivitiesModal?.classList.remove('flex');
    };

    openSubjectivitiesButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const habitId = button.dataset.habitId ?? '';
            const habitTitle = button.dataset.habitTitle ?? '';
            const goalTitle = button.dataset.goalTitle ?? '';
            const parentGoalId = button.dataset.parentGoalId ?? '';
            const repetitionKind = button.dataset.repetitionKind ?? 'unlimited';
            const repetitionLimit = button.dataset.repetitionLimit ?? '';
            const repetitionEveryValue = button.dataset.repetitionEveryValue ?? '';
            const repetitionEveryUnit = button.dataset.repetitionEveryUnit ?? 'minute';
            const repetitionStartAt = button.dataset.repetitionStartAt ?? '';
            const repetitionEndAt = button.dataset.repetitionEndAt ?? '';
            openSubjectivitiesModal(
                habitId,
                habitTitle,
                goalTitle,
                parentGoalId,
                repetitionKind,
                repetitionLimit,
                repetitionEveryValue,
                repetitionEveryUnit,
                repetitionStartAt,
                repetitionEndAt
            );
        });
    });

    editRepetitionTypeInput?.addEventListener('change', toggleEditRepetitionLimit);

    closeSubjectivitiesModalButton?.addEventListener('click', closeSubjectivitiesModal);
    cancelSubjectivitiesModalButton?.addEventListener('click', closeSubjectivitiesModal);

    subjectivitiesModal?.addEventListener('click', (event) => {
        if (event.target === subjectivitiesModal) {
            closeSubjectivitiesModal();
        }
    });
</script>
