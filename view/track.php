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
            h.notes,
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
                                    <?php if (!empty($habit['notes'])): ?>
                                        <p class="mt-2 text-sm text-slate-400"><?= nl2br(htmlspecialchars((string) $habit['notes'], ENT_QUOTES, 'UTF-8')); ?></p>
                                    <?php endif; ?>
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
                <label for="notes" class="mb-1 block text-sm text-slate-300">Notas (opcional)</label>
                <textarea id="notes" name="notes" rows="3" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" id="cancelHabitModal" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">Cancelar</button>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-slate-900 hover:brightness-110">Salvar hábito</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('habitModal');
    const openButton = document.getElementById('openHabitModal');
    const closeButton = document.getElementById('closeHabitModal');
    const cancelButton = document.getElementById('cancelHabitModal');

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
</script>
