<?php

if (session_status() === PHP_SESSION_NONE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("Erro na conexão: DATABASE_URL não definida.");
}

$parts = parse_url($databaseUrl);

if ($parts === false) {
    die("Erro na conexão: DATABASE_URL inválida.");
}

$host = $parts['host'] ?? '';
$port = $parts['port'] ?? 5432;
$dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';
$user = $parts['user'] ?? '';
$password = urldecode($parts['pass'] ?? '');

if ($host === '' || $user === '' || $dbname === '') {
    die("Erro na conexão: dados incompletos na DATABASE_URL.");
}

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("SET NAMES 'UTF8'");
    $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");

} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}