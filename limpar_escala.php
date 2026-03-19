<?php
require "db.php";

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

try {
    $pdo->beginTransaction();

    $pdo->exec("DELETE FROM driver_clusters");
    $pdo->exec("DELETE FROM drivers");
    $pdo->exec("UPDATE imports SET is_active = false WHERE is_active = true");
    $pdo->exec("DELETE FROM mesa_controle");
    $pdo->exec("DELETE FROM mesas_controle");
    $pdo->exec("DELETE FROM mesa_tempos");

    $pdo->commit();

    header("Location: admin.php");
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die("Erro ao limpar escala: " . $e->getMessage());
}