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
    if ($action === "start") {
        $mesa = trim((string)($_POST["mesa"] ?? ""));
        $driverRef = $_POST["driver_ref"] ?? null;
        $driverId = trim((string)($_POST["driver_id"] ?? ""));
        $driverName = trim((string)($_POST["driver_name"] ?? ""));
        $rota = trim((string)($_POST["rota_texto"] ?? ""));
        $vehicle = trim((string)($_POST["vehicle_type"] ?? ""));

        if ($mesa === "" || $rota === "") {
            responder(["ok" => false, "erro" => "Dados inválidos"], 400);
        }

        $pdo->prepare("
            UPDATE mesa_tempos
            SET finished_at = NOW()
            WHERE mesa = :mesa
              AND finished_at IS NULL
        ")->execute([
            ":mesa" => $mesa
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO mesa_tempos
                (mesa, driver_ref, driver_id, driver_name, rota_texto, vehicle_type, started_at)
            VALUES
                (:mesa, :driver_ref, :driver_id, :driver_name, :rota_texto, :vehicle_type, NOW())
            RETURNING started_at
        ");

        $stmt->execute([
            ":mesa" => $mesa,
            ":driver_ref" => $driverRef !== "" ? $driverRef : null,
            ":driver_id" => $driverId !== "" ? $driverId : null,
            ":driver_name" => $driverName !== "" ? $driverName : null,
            ":rota_texto" => $rota,
            ":vehicle_type" => $vehicle !== "" ? $vehicle : null
        ]);

        $started = $stmt->fetch(PDO::FETCH_ASSOC);

        responder([
            "ok" => true,
            "started_at" => $started["started_at"] ?? null
        ]);
    }

    if ($action === "finish") {
        $mesa = trim((string)($_POST["mesa"] ?? ""));

        if ($mesa === "") {
            responder(["ok" => false, "erro" => "Mesa inválida"], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE mesa_tempos
            SET finished_at = NOW()
            WHERE mesa = :mesa
              AND finished_at IS NULL
            RETURNING started_at, finished_at, rota_texto
        ");

        $stmt->execute([
            ":mesa" => $mesa
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder([
                "ok" => false,
                "erro" => "Nenhum cronômetro ativo encontrado para essa mesa."
            ], 404);
        }

        $inicio = new DateTime($row["started_at"]);
        $fim = new DateTime($row["finished_at"]);
        $segundos = max(0, $fim->getTimestamp() - $inicio->getTimestamp());

        responder([
            "ok" => true,
            "duration_seconds" => $segundos,
            "rota_texto" => $row["rota_texto"] ?? ""
        ]);
    }

    if ($action === "status") {
        $mesa = trim((string)($_GET["mesa"] ?? ""));

        if ($mesa === "") {
            responder(["ok" => false, "erro" => "Mesa inválida"], 400);
        }

        $stmt = $pdo->prepare("
            SELECT id, mesa, driver_ref, driver_id, driver_name, rota_texto, vehicle_type, started_at, finished_at
            FROM mesa_tempos
            WHERE mesa = :mesa
              AND finished_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1
        ");

        $stmt->execute([
            ":mesa" => $mesa
        ]);

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
                "id" => $row["id"],
                "mesa" => $row["mesa"],
                "driver_ref" => $row["driver_ref"],
                "driver_id" => $row["driver_id"],
                "driver_name" => $row["driver_name"],
                "rota_texto" => $row["rota_texto"],
                "vehicle_type" => $row["vehicle_type"],
                "started_at" => $row["started_at"]
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