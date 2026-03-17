<?php
require "db.php";
session_start();

header("Content-Type: application/json; charset=utf-8");

function sair($ok, $dados = [], $http = 200){
    http_response_code($http);
    echo json_encode(array_merge(["ok" => $ok], $dados), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION["user"])) {
    sair(false, ["erro" => "Não autenticado"], 401);
}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

try {
    if ($action === "save_search") {
        $mesa = (int)($_POST["mesa"] ?? 0);
        $driverDbId = (int)($_POST["driver_db_id"] ?? 0);
        $driverId = trim($_POST["driver_id"] ?? "");
        $rotaTexto = trim($_POST["rota_texto"] ?? "");

        if ($mesa <= 0 || $driverDbId <= 0 || $driverId === "" || $rotaTexto === "") {
            sair(false, ["erro" => "Dados inválidos para salvar mesa"], 400);
        }

        $stmt = $pdo->prepare("
            insert into mesa_controle (mesa_numero, driver_db_id, driver_id, rota_texto, status_local)
            values (?, ?, ?, ?, 'pesquisado')
            on conflict (mesa_numero)
            do update set
                driver_db_id = excluded.driver_db_id,
                driver_id = excluded.driver_id,
                rota_texto = excluded.rota_texto,
                status_local = 'pesquisado',
                updated_at = now()
        ");
        $stmt->execute([$mesa, $driverDbId, $driverId, $rotaTexto]);

        sair(true);
    }

    if ($action === "set_conferindo") {
        $mesa = (int)($_POST["mesa"] ?? 0);
        $driverDbId = (int)($_POST["driver_db_id"] ?? 0);
        $driverId = trim($_POST["driver_id"] ?? "");
        $rotaTexto = trim($_POST["rota_texto"] ?? "");

        if ($mesa <= 0 || $driverDbId <= 0 || $driverId === "" || $rotaTexto === "") {
            sair(false, ["erro" => "Dados inválidos para conferir"], 400);
        }

        $stmtConflito = $pdo->prepare("
            select mesa_numero
            from mesa_controle
            where rota_texto = ?
              and status_local = 'conferindo'
              and mesa_numero <> ?
            order by updated_at asc
            limit 1
        ");
        $stmtConflito->execute([$rotaTexto, $mesa]);
        $conflito = $stmtConflito->fetch(PDO::FETCH_ASSOC);

        if ($conflito) {
            sair(false, [
                "conflito" => true,
                "mesa" => (int)$conflito["mesa_numero"],
                "mensagem" => "Essa rota está na Mesa " . (int)$conflito["mesa_numero"] . ". Direcione o motorista para lá."
            ]);
        }

        $stmt = $pdo->prepare("
            insert into mesa_controle (mesa_numero, driver_db_id, driver_id, rota_texto, status_local)
            values (?, ?, ?, ?, 'conferindo')
            on conflict (mesa_numero)
            do update set
                driver_db_id = excluded.driver_db_id,
                driver_id = excluded.driver_id,
                rota_texto = excluded.rota_texto,
                status_local = 'conferindo',
                updated_at = now()
        ");
        $stmt->execute([$mesa, $driverDbId, $driverId, $rotaTexto]);

        sair(true);
    }

    if ($action === "check_conflict") {
        $mesa = (int)($_GET["mesa"] ?? 0);
        $rotaTexto = trim($_GET["rota_texto"] ?? "");

        if ($mesa <= 0 || $rotaTexto === "") {
            sair(false, ["erro" => "Dados inválidos"], 400);
        }

        $stmt = $pdo->prepare("
            select mesa_numero
            from mesa_controle
            where rota_texto = ?
              and status_local = 'conferindo'
              and mesa_numero <> ?
            order by updated_at asc
            limit 1
        ");
        $stmt->execute([$rotaTexto, $mesa]);
        $conflito = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conflito) {
            sair(true, [
                "conflito" => true,
                "mesa" => (int)$conflito["mesa_numero"],
                "mensagem" => "Essa rota está na Mesa " . (int)$conflito["mesa_numero"] . ". Direcione o motorista para lá."
            ]);
        }

        sair(true, ["conflito" => false]);
    }

    if ($action === "clear") {
        $mesa = (int)($_POST["mesa"] ?? 0);

        if ($mesa <= 0) {
            sair(false, ["erro" => "Mesa inválida"], 400);
        }

        $stmt = $pdo->prepare("delete from mesa_controle where mesa_numero = ?");
        $stmt->execute([$mesa]);

        sair(true);
    }

    sair(false, ["erro" => "Ação inválida"], 400);

} catch (Throwable $e) {
    sair(false, ["erro" => $e->getMessage()], 500);
}