<?php
require "db.php";
header("Content-Type: application/json; charset=utf-8");

$action = $_POST["action"] ?? $_GET["action"] ?? "";

if ($action === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "erro" => "Ação não informada"]);
    exit;
}

function responder($data, $status = 200){
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === "save_search") {
        $mesa = trim((string)($_POST["mesa"] ?? ""));
        $driverDbId = trim((string)($_POST["driver_db_id"] ?? ""));
        $driverId = trim((string)($_POST["driver_id"] ?? ""));
        $rotaTexto = trim((string)($_POST["rota_texto"] ?? ""));

        if ($mesa === "") responder(["ok" => false, "erro" => "Mesa inválida"], 400);

        $stmt = $pdo->prepare("
            INSERT INTO mesas_controle (mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at)
            VALUES (:mesa, :driver_db_id, :driver_id, :rota_texto, 'pesquisado', NOW())
            ON CONFLICT (mesa)
            DO UPDATE SET
                driver_db_id = EXCLUDED.driver_db_id,
                driver_id = EXCLUDED.driver_id,
                rota_texto = EXCLUDED.rota_texto,
                status_mesa = 'pesquisado',
                updated_at = NOW()
        ");
        $stmt->execute([
            ":mesa" => $mesa,
            ":driver_db_id" => $driverDbId !== "" ? $driverDbId : null,
            ":driver_id" => $driverId !== "" ? $driverId : null,
            ":rota_texto" => $rotaTexto !== "" ? $rotaTexto : null,
        ]);

        responder(["ok" => true]);
    }

    if ($action === "set_conferindo") {
        $mesa = trim((string)($_POST["mesa"] ?? ""));
        $driverDbId = trim((string)($_POST["driver_db_id"] ?? ""));
        $driverId = trim((string)($_POST["driver_id"] ?? ""));
        $rotaTexto = trim((string)($_POST["rota_texto"] ?? ""));

        if ($mesa === "" || $rotaTexto === "") {
            responder(["ok" => false, "erro" => "Dados inválidos"], 400);
        }

        $stmtConflito = $pdo->prepare("
            SELECT mesa, driver_id, rota_texto
            FROM mesas_controle
            WHERE rota_texto = :rota_texto
              AND mesa <> :mesa
              AND status_mesa = 'conferindo'
            LIMIT 1
        ");
        $stmtConflito->execute([
            ":rota_texto" => $rotaTexto,
            ":mesa" => $mesa
        ]);
        $conflito = $stmtConflito->fetch(PDO::FETCH_ASSOC);

        if ($conflito) {
            responder([
                "ok" => false,
                "mensagem" => "Essa rota está na mesa {$conflito['mesa']}. Direcione o motorista para lá."
            ]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO mesas_controle (mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at)
            VALUES (:mesa, :driver_db_id, :driver_id, :rota_texto, 'conferindo', NOW())
            ON CONFLICT (mesa)
            DO UPDATE SET
                driver_db_id = EXCLUDED.driver_db_id,
                driver_id = EXCLUDED.driver_id,
                rota_texto = EXCLUDED.rota_texto,
                status_mesa = 'conferindo',
                updated_at = NOW()
        ");
        $stmt->execute([
            ":mesa" => $mesa,
            ":driver_db_id" => $driverDbId !== "" ? $driverDbId : null,
            ":driver_id" => $driverId !== "" ? $driverId : null,
            ":rota_texto" => $rotaTexto
        ]);

        responder(["ok" => true]);
    }

    if ($action === "check_conflict") {
        $mesa = trim((string)($_GET["mesa"] ?? ""));
        $rotaTexto = trim((string)($_GET["rota_texto"] ?? ""));

        if ($mesa === "" || $rotaTexto === "") {
            responder(["ok" => false, "erro" => "Dados inválidos"], 400);
        }

        $stmt = $pdo->prepare("
            SELECT mesa, driver_id, rota_texto
            FROM mesas_controle
            WHERE rota_texto = :rota_texto
              AND mesa <> :mesa
              AND status_mesa = 'conferindo'
            LIMIT 1
        ");
        $stmt->execute([
            ":rota_texto" => $rotaTexto,
            ":mesa" => $mesa
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            responder([
                "ok" => true,
                "conflito" => true,
                "mensagem" => "Essa rota está na mesa {$row['mesa']}. Direcione o motorista para lá."
            ]);
        }

        responder([
            "ok" => true,
            "conflito" => false
        ]);
    }

    if ($action === "clear") {
        $mesa = trim((string)($_POST["mesa"] ?? ""));

        if ($mesa === "") responder(["ok" => false, "erro" => "Mesa inválida"], 400);

        $stmt = $pdo->prepare("DELETE FROM mesas_controle WHERE mesa = :mesa");
        $stmt->execute([":mesa" => $mesa]);

        responder(["ok" => true]);
    }

    responder(["ok" => false, "erro" => "Ação inválida"], 400);

} catch (Throwable $e) {
    responder([
        "ok" => false,
        "erro" => $e->getMessage()
    ], 500);
}