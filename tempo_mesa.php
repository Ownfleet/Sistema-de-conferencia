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
        $mesa = isset($_POST["mesa"]) ? (int)$_POST["mesa"] : 0;
        $driverRef = isset($_POST["driver_ref"]) && $_POST["driver_ref"] !== "" ? (int)$_POST["driver_ref"] : null;
        $driverId = trim((string)($_POST["driver_id"] ?? ""));
        $driverName = trim((string)($_POST["driver_name"] ?? ""));
        $rotaTexto = trim((string)($_POST["rota_texto"] ?? ""));
        $tipoVeiculo = trim((string)($_POST["vehicle_type"] ?? ""));

        if ($mesa <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."]);
        }

        if ($rotaTexto === "") {
            responder(["ok" => false, "erro" => "Rota não informada."]);
        }

        $stmtFecha = $pdo->prepare("
            UPDATE mesa_tempos
            SET finished_at = NOW(),
                status_mesa = 'finalizado'
            WHERE mesa_numero = :mesa_numero
              AND finished_at IS NULL
        ");
        $stmtFecha->execute([
            ":mesa_numero" => $mesa
        ]);

        $stmtInsere = $pdo->prepare("
            INSERT INTO mesa_tempos
            (
                mesa_numero,
                driver_ref,
                driver_id,
                nome_do_motorista,
                rota_texto,
                tipo_de_veiculo,
                started_at,
                status_mesa
            )
            VALUES
            (
                :mesa_numero,
                :driver_ref,
                :driver_id,
                :nome_do_motorista,
                :rota_texto,
                :tipo_de_veiculo,
                NOW(),
                'conferindo'
            )
            RETURNING started_at
        ");

        $stmtInsere->execute([
            ":mesa_numero" => $mesa,
            ":driver_ref" => $driverRef,
            ":driver_id" => $driverId !== "" ? $driverId : null,
            ":nome_do_motorista" => $driverName !== "" ? $driverName : null,
            ":rota_texto" => $rotaTexto,
            ":tipo_de_veiculo" => $tipoVeiculo !== "" ? $tipoVeiculo : null
        ]);

        $row = $stmtInsere->fetch(PDO::FETCH_ASSOC);

        responder([
            "ok" => true,
            "started_at" => $row["started_at"] ?? null
        ]);
    }

    if ($action === "finish") {
        $mesa = isset($_POST["mesa"]) ? (int)$_POST["mesa"] : 0;

        if ($mesa <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."]);
        }

        $stmtFim = $pdo->prepare("
            UPDATE mesa_tempos
            SET finished_at = NOW(),
                status_mesa = 'finalizado'
            WHERE id = (
                SELECT id
                FROM mesa_tempos
                WHERE mesa_numero = :mesa_numero
                  AND finished_at IS NULL
                ORDER BY started_at DESC
                LIMIT 1
            )
            RETURNING started_at, finished_at, rota_texto
        ");

        $stmtFim->execute([
            ":mesa_numero" => $mesa
        ]);

        $row = $stmtFim->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder([
                "ok" => false,
                "erro" => "Nenhum cronômetro ativo encontrado para essa mesa."
            ]);
        }

        $inicio = new DateTime($row["started_at"]);
        $fim = new DateTime($row["finished_at"]);
        $duracao = max(0, $fim->getTimestamp() - $inicio->getTimestamp());

        responder([
            "ok" => true,
            "duration_seconds" => $duracao,
            "rota_texto" => $row["rota_texto"] ?? ""
        ]);
    }

    if ($action === "status") {
        $mesa = isset($_GET["mesa"]) ? (int)$_GET["mesa"] : 0;

        if ($mesa <= 0) {
            responder(["ok" => false, "erro" => "Mesa inválida ou não enviada."]);
        }

        $stmtBusca = $pdo->prepare("
            SELECT
                id,
                mesa_numero,
                driver_ref,
                driver_id,
                nome_do_motorista,
                rota_texto,
                tipo_de_veiculo,
                started_at,
                finished_at,
                status_mesa
            FROM mesa_tempos
            WHERE mesa_numero = :mesa_numero
              AND finished_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1
        ");

        $stmtBusca->execute([
            ":mesa_numero" => $mesa
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
                "nome_do_motorista" => $row["nome_do_motorista"],
                "rota_texto" => $row["rota_texto"],
                "tipo_de_veiculo" => $row["tipo_de_veiculo"],
                "started_at" => $row["started_at"],
                "status_mesa" => $row["status_mesa"]
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