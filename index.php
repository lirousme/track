<?php

declare(strict_types=1);

session_start();

$allowedViews = ['login', 'track'];
$view = $_GET['view'] ?? 'login';

if (!in_array($view, $allowedViews, true)) {
    $view = 'login';
}

$isAuthenticated = isset($_SESSION['user']);

if ($isAuthenticated && $view === 'login') {
    header('Location: /track/index.php?view=track');
    exit;
}

if (!$isAuthenticated && $view === 'track') {
    header('Location: /track/index.php?view=login');
    exit;
}

$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Track</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: '#38bdf8',
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<main class="mx-auto flex min-h-screen w-full max-w-4xl items-center justify-center px-4">
    <?php require __DIR__ . '/view/' . $view . '.php'; ?>
</main>
</body>
</html>
