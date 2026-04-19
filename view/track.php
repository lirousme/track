<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

?>
<section class="w-full rounded-2xl border border-slate-800 bg-slate-900/70 p-8 shadow-2xl shadow-slate-950">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Track</h1>
            <p class="text-sm text-slate-400">Bem-vindo, <?= htmlspecialchars((string) ($_SESSION['user']['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>
        <a href="<?= htmlspecialchars(trackUrl('/api/logout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
            Sair
        </a>
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-6 text-slate-300">
        <p>Você está na view <span class="font-semibold text-brand">view=track</span>.</p>
        <p class="mt-2 text-sm text-slate-400">Aqui você pode evoluir o seu sistema de traquejo/rastreamento.</p>
    </div>
</section>
