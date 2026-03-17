<?php
require "db.php";
session_start();

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "erro" => "Não autenticado"]);
    exit;
}

$id = $_POST["id"] ?? null;
$status = $_POST["status"] ?? null;

if (!$id || !$status) {
    http_response_code(400);
    echo json_encode(["ok" => false, "erro" => "Dados inválidos"]);
    exit;
}

if (!in_array($status, ["ativo", "conferindo", "finalizado"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "erro" => "Status inválido"]);
    exit;
}

$stmt = $pdo->prepare("UPDATE drivers SET status = ? WHERE id = ?");
$stmt->execute([$status, $id]);

echo json_encode([
    "ok" => true,
    "id" => $id,
    "status" => $status
]);