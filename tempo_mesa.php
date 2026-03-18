<?php
require "db.php";
header("Content-Type: application/json; charset=utf-8");

function responder($data, $status = 200){
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

if (!$action) {
    responder(["ok" => false, "erro" => "Ação não informada"], 400);
}

try {

    // ================================
    // ▶️ INICIAR CRONÔMETRO
    // ================================
    if ($action === "start") {

        $mesa = $_POST["mesa"] ?? "";
        $driverRef = $_POST["driver_ref"] ?? null;
        $driverId = $_POST["driver_id"] ?? "";
        $driverName = $_POST["driver_name"] ?? "";
        $rota = $_POST["rota_texto"] ?? "";
        $vehicle = $_POST["vehicle_type"] ?? "";

        if (!$mesa || !$rota) {
            responder(["ok" => false, "erro" => "Dados inválidos"], 400);
        }

        // Fecha qualquer anterior aberta na mesma mesa
        $pdo->prepare("
            UPDATE tempo_mesa 
            SET finished_at = NOW()
            WHERE mesa = :mesa AND finished_at IS NULL
        ")->execute([":mesa" => $mesa]);

        // Cria novo registro
        $stmt = $pdo->prepare("
            INSERT INTO tempo_mesa 
            (mesa, driver_ref, driver_id, driver_name, rota_texto, vehicle_type, started_at)
            VALUES (:mesa, :driver_ref, :driver_id, :driver_name, :rota_texto, :vehicle_type, NOW())
            RETURNING started_at
        ");

        $stmt->execute([
            ":mesa" => $mesa,
            ":driver_ref" => $driverRef,
            ":driver_id" => $driverId,
            ":driver_name" => $driverName,
            ":rota_texto" => $rota,
            ":vehicle_type" => $vehicle
        ]);

        $started = $stmt->fetch(PDO::FETCH_ASSOC);

        responder([
            "ok" => true,
            "started_at" => $started["started_at"]
        ]);
    }

    // ================================
    // ⏹ FINALIZAR CRONÔMETRO
    // ================================
    if ($action === "finish") {

        $mesa = $_POST["mesa"] ?? "";
        $rota = $_POST["rota_texto"] ?? "";

        if (!$mesa) {
            responder(["ok" => false, "erro" => "Mesa inválida"], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE tempo_mesa
            SET finished_at = NOW()
            WHERE mesa = :mesa
              AND finished_at IS NULL
            RETURNING started_at, finished_at
        ");

        $stmt->execute([":mesa" => $mesa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder([
                "ok" => false,
                "erro" => "Nenhum tempo ativo encontrado."
            ]);
        }

        $inicio = new DateTime($row["started_at"]);
        $fim = new DateTime($row["finished_at"]);

        $segundos = $fim->getTimestamp() - $inicio->getTimestamp();

        responder([
            "ok" => true,
            "duration_seconds" => $segundos
        ]);
    }

    // ================================
    // 🔄 STATUS ATUAL (cronômetro rodando)
    // ================================
    if ($action === "status") {

        $mesa = $_GET["mesa"] ?? "";

        if (!$mesa) {
            responder(["ok" => false, "erro" => "Mesa inválida"], 400);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM tempo_mesa
            WHERE mesa = :mesa
              AND finished_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1
        ");

        $stmt->execute([":mesa" => $mesa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder([
                "ok" => true,
                "ativo" => false
            ]);
        }

        responder([
            "ok" => true,
            "ativo" => true,
            "registro" => [
                "started_at" => $row["started_at"],
                "rota_texto" => $row["rota_texto"]
            ]
        ]);
    }

    responder(["ok" => false, "erro" => "Ação inválida"], 400);

} catch (Throwable $e) {
    responder([
        "ok" => false,
        "erro" => $e->getMessage()
    ], 500);
}