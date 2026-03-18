<?php
require "db.php";
header("Content-Type: application/json; charset=utf-8");

function responder($data, $status = 200){
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $id = $_POST["id"] ?? null;
    $status = trim((string)($_POST["status"] ?? ""));

    if (!$id || $status === "") {
        responder([
            "ok" => false,
            "erro" => "ID ou status não informado."
        ], 400);
    }

    $statusPermitidos = ["ativo", "conferindo", "finalizado"];

    if (!in_array($status, $statusPermitidos, true)) {
        responder([
            "ok" => false,
            "erro" => "Status inválido."
        ], 400);
    }

    $stmtBusca = $pdo->prepare("
        SELECT id, driver_id, driver_name, cluster_text, status
        FROM drivers
        WHERE id = :id
        LIMIT 1
    ");
    $stmtBusca->execute([":id" => $id]);
    $driver = $stmtBusca->fetch(PDO::FETCH_ASSOC);

    if (!$driver) {
        responder([
            "ok" => false,
            "erro" => "Motorista não encontrado."
        ], 404);
    }

    $stmt = $pdo->prepare("
        UPDATE drivers
        SET status = :status
        WHERE id = :id
    ");
    $stmt->execute([
        ":status" => $status,
        ":id" => $id
    ]);

    responder([
        "ok" => true,
        "mensagem" => "Status atualizado com sucesso.",
        "driver" => [
            "id" => $driver["id"],
            "driver_id" => $driver["driver_id"],
            "driver_name" => $driver["driver_name"],
            "cluster_text" => $driver["cluster_text"],
            "status_anterior" => $driver["status"],
            "status_atual" => $status
        ]
    ]);

} catch (Throwable $e) {
    responder([
        "ok" => false,
        "erro" => $e->getMessage()
    ], 500);
}