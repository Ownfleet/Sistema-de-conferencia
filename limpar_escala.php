<?php
require "db.php";


if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

$pdo->exec("UPDATE drivers SET active = false WHERE active = true");
$pdo->exec("UPDATE imports SET is_active = false WHERE is_active = true");

header("Location: admin.php");
exit;