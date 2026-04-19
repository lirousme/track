<section class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/70 p-8 shadow-2xl shadow-slate-950">
    <h1 class="mb-2 text-2xl font-bold">Entrar no sistema</h1>
    <p class="mb-6 text-sm text-slate-400">Use apenas usuário e senha.</p>

    <?php if (!empty($flashError)): ?>
        <div class="mb-4 rounded-lg border border-rose-400/50 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            <?= htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars(trackUrl('/api/login.php'), ENT_QUOTES, 'UTF-8'); ?>" method="post" class="space-y-4">
        <div>
            <label for="username" class="mb-1 block text-sm font-medium text-slate-300">Usuário</label>
            <input id="username" name="username" type="text" required
                   class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-slate-300">Senha</label>
            <input id="password" name="password" type="password" required
                   class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-brand focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand px-4 py-2 font-semibold text-slate-950 transition hover:brightness-110">
            Login
        </button>
    </form>
</section>
