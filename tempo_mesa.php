<?php
require "db.php";
header("Content-Type: application/json; charset=utf-8");

function responder($data, $status = 200){
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

if ($action === "") {
    responder(["ok" => false, "erro" => "Ação não informada"], 400);
}

try {
    if ($action === "start") {
        $mesaNumero = isset($_POST["mesa"]) ? (int)$_POST["mesa"] : 0;
        $driverRef = isset($_POST["driver_ref"]) && $_POST["driver_ref"] !== "" ? (int)$_POST["driver_ref"] : null;
        $driverId = trim((string)($_POST["driver_id"] ?? ""));
        $driverName = trim((string)($_POST["driver_name"] ?? ""));
        $rotaTexto = trim((string)($_POST["rota_texto"] ?? ""));
        $vehicleType = trim((string)($_POST["vehicle_type"] ?? ""));

        if ($mesaNumero <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."], 400);
        }

        if ($rotaTexto === "") {
            responder(["ok" => false, "erro" => "Rota não informada."], 400);
        }

        $stmtFecha = $pdo->prepare("
            UPDATE mesa_tempos
            SET
                finished_at = NOW(),
                duration_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::int,
                status = 'finalizado'
            WHERE mesa_numero = :mesa_numero
              AND finished_at IS NULL
        ");
        $stmtFecha->execute([
            ":mesa_numero" => $mesaNumero
        ]);

        $stmtInsere = $pdo->prepare("
            INSERT INTO mesa_tempos
            (
                mesa_numero,
                driver_ref,
                driver_id,
                driver_name,
                rota_texto,
                vehicle_type,
                started_at,
                status,
                created_at
            )
            VALUES
            (
                :mesa_numero,
                :driver_ref,
                :driver_id,
                :driver_name,
                :rota_texto,
                :vehicle_type,
                NOW(),
                'conferindo',
                NOW()
            )
            RETURNING started_at
        ");

        $stmtInsere->execute([
            ":mesa_numero" => $mesaNumero,
            ":driver_ref" => $driverRef,
            ":driver_id" => $driverId !== "" ? $driverId : null,
            ":driver_name" => $driverName !== "" ? $driverName : null,
            ":rota_texto" => $rotaTexto,
            ":vehicle_type" => $vehicleType !== "" ? $vehicleType : null
        ]);

        $row = $stmtInsere->fetch(PDO::FETCH_ASSOC);

        responder([
            "ok" => true,
            "started_at" => $row["started_at"] ?? null
        ]);
    }

    if ($action === "finish") {
        $mesaNumero = isset($_POST["mesa"]) ? (int)$_POST["mesa"] : 0;

        if ($mesaNumero <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."], 400);
        }

        $stmtFim = $pdo->prepare("
            UPDATE mesa_tempos
            SET
                finished_at = NOW(),
                duration_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::int,
                status = 'finalizado'
            WHERE id = (
                SELECT id
                FROM mesa_tempos
                WHERE mesa_numero = :mesa_numero
                  AND finished_at IS NULL
                ORDER BY started_at DESC
                LIMIT 1
            )
            RETURNING started_at, finished_at, duration_seconds, rota_texto
        ");

        $stmtFim->execute([
            ":mesa_numero" => $mesaNumero
        ]);

        $row = $stmtFim->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder([
                "ok" => false,
                "erro" => "Nenhum cronômetro ativo encontrado para essa mesa."
            ], 404);
        }

        responder([
            "ok" => true,
            "duration_seconds" => (int)($row["duration_seconds"] ?? 0),
            "rota_texto" => $row["rota_texto"] ?? ""
        ]);
    }

    if ($action === "status") {
        $mesaNumero = isset($_GET["mesa"]) ? (int)$_GET["mesa"] : 0;

        if ($mesaNumero <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."], 400);
        }

        $stmtBusca = $pdo->prepare("
            SELECT
                id,
                mesa_numero,
                driver_ref,
                driver_id,
                driver_name,
                rota_texto,
                vehicle_type,
                started_at,
                finished_at,
                duration_seconds,
                status,
                created_at
            FROM mesa_tempos
            WHERE mesa_numero = :mesa_numero
              AND finished_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1
        ");

        $stmtBusca->execute([
            ":mesa_numero" => $mesaNumero
        ]);

        $row = $stmtBusca->fetch(PDO::FETCH_ASSOC);

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
                "id" => $row["id"],
                "mesa_numero" => $row["mesa_numero"],
                "driver_ref" => $row["driver_ref"],
                "driver_id" => $row["driver_id"],
                "driver_name" => $row["driver_name"],
                "rota_texto" => $row["rota_texto"],
                "vehicle_type" => $row["vehicle_type"],
                "started_at" => $row["started_at"],
                "duration_seconds" => $row["duration_seconds"],
                "status" => $row["status"],
                "created_at" => $row["created_at"]
            ]
        ]);
    }

    responder(["ok" => false, "erro" => "Ação inválida."], 400);

} catch (Throwable $e) {
    responder([
        "ok" => false,
        "erro" => $e->getMessage()
    ], 500);
}