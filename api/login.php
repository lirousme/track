<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $_SESSION['flash_error'] = 'Informe usuário e senha.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

try {
    $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
        ];

        header('Location: ' . trackUrl('/index.php?view=track'));
        exit;
    }

    $_SESSION['flash_error'] = 'Usuário ou senha inválidos.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
} catch (Throwable $exception) {
    $_SESSION['flash_error'] = 'Erro ao autenticar. Verifique sua configuração de banco.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}
