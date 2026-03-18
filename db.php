<?php

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
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}