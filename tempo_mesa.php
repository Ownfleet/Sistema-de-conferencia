<?php
require "db.php";

header("Content-Type: application/json; charset=utf-8");

$action = $_REQUEST["action"] ?? "";

function json_out($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === "start") {
    $mesa = (int)($_POST["mesa"] ?? 0);
    $driverRef = (int)($_POST["driver_ref"] ?? 0);
    $driverId = trim($_POST["driver_id"] ?? "");
    $driverName = trim($_POST["driver_name"] ?? "");
    $rotaTexto = trim($_POST["rota_texto"] ?? "");
    $vehicleType = trim($_POST["vehicle_type"] ?? "");

    if ($mesa <= 0 || $rotaTexto === "") {
        json_out(["ok" => false, "erro" => "Dados inválidos para iniciar cronômetro."], 400);
    }

    $stmtAberto = $pdo->prepare("
        SELECT id
        FROM mesa_tempos
        WHERE mesa_numero = ?
          AND status = 'conferindo'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtAberto->execute([$mesa]);
    $aberto = $stmtAberto->fetch();

    if ($aberto) {
        json_out(["ok" => false, "erro" => "Já existe um cronômetro em andamento nesta mesa."], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO mesa_tempos (
            mesa_numero, driver_ref, driver_id, driver_name, rota_texto, vehicle_type, started_at, status
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'conferindo')
        RETURNING id, started_at
    ");
    $stmt->execute([$mesa, $driverRef ?: null, $driverId, $driverName, $rotaTexto, $vehicleType]);
    $row = $stmt->fetch();

    json_out([
        "ok" => true,
        "tempo_id" => $row["id"],
        "started_at" => $row["started_at"]
    ]);
}

if ($action === "finish") {
    $mesa = (int)($_POST["mesa"] ?? 0);
    $rotaTexto = trim($_POST["rota_texto"] ?? "");

    if ($mesa <= 0 || $rotaTexto === "") {
        json_out(["ok" => false, "erro" => "Dados inválidos para finalizar cronômetro."], 400);
    }

    $stmt = $pdo->prepare("
        SELECT id, started_at
        FROM mesa_tempos
        WHERE mesa_numero = ?
          AND rota_texto = ?
          AND status = 'conferindo'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$mesa, $rotaTexto]);
    $row = $stmt->fetch();

    if (!$row) {
        json_out(["ok" => false, "erro" => "Nenhum cronômetro em andamento encontrado para esta mesa/rota."], 404);
    }

    $stmtUpd = $pdo->prepare("
        UPDATE mesa_tempos
        SET
            finished_at = NOW(),
            duration_seconds = GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - started_at)))::int),
            status = 'finalizado'
        WHERE id = ?
        RETURNING id, started_at, finished_at, duration_seconds
    ");
    $stmtUpd->execute([$row["id"]]);
    $upd = $stmtUpd->fetch();

    json_out([
        "ok" => true,
        "tempo_id" => $upd["id"],
        "started_at" => $upd["started_at"],
        "finished_at" => $upd["finished_at"],
        "duration_seconds" => (int)$upd["duration_seconds"]
    ]);
}

if ($action === "status") {
    $mesa = (int)($_GET["mesa"] ?? 0);

    if ($mesa <= 0) {
        json_out(["ok" => false, "erro" => "Mesa inválida."], 400);
    }

    $stmt = $pdo->prepare("
        SELECT id, mesa_numero, driver_ref, driver_id, driver_name, rota_texto, vehicle_type, started_at, status
        FROM mesa_tempos
        WHERE mesa_numero = ?
          AND status = 'conferindo'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$mesa]);
    $row = $stmt->fetch();

    if (!$row) {
        json_out(["ok" => true, "ativo" => false]);
    }

    json_out([
        "ok" => true,
        "ativo" => true,
        "registro" => $row
    ]);
}

json_out(["ok" => false, "erro" => "Ação inválida."], 400);