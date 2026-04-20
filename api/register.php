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
    $_SESSION['flash_error'] = 'Informe usuário e senha para criar a conta.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

if (mb_strlen($username) > 50) {
    $_SESSION['flash_error'] = 'O usuário deve ter no máximo 50 caracteres.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

if (mb_strlen($password) < 6) {
    $_SESSION['flash_error'] = 'A senha deve ter pelo menos 6 caracteres.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}

try {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);

    if ($stmt->fetch()) {
        $_SESSION['flash_error'] = 'Este usuário já existe.';
        header('Location: ' . trackUrl('/index.php?view=login'));
        exit;
    }

    $insert = db()->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $_SESSION['flash_success'] = 'Conta criada com sucesso. Faça o login para continuar.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
} catch (Throwable $exception) {
    $_SESSION['flash_error'] = 'Erro ao criar conta. Verifique sua configuração de banco.';
    header('Location: ' . trackUrl('/index.php?view=login'));
    exit;
}
