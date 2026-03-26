<?php
require "db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$action = trim((string)($_POST["action"] ?? $_GET["action"] ?? ""));

function responder(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function limparTexto($valor): string {
    return trim((string)$valor);
}

function statusMesaValido(string $status): string {
    $status = mb_strtolower(trim($status), "UTF-8");

    if (in_array($status, ["pesquisado", "conferindo"], true)) {
        return $status;
    }

    return "pesquisado";
}

function buscarMesaPorNumero(PDO $pdo, string $mesa): ?array {
    $stmt = $pdo->prepare("
        SELECT
            mesa,
            driver_db_id,
            driver_id,
            rota_texto,
            status_mesa,
            updated_at
        FROM mesas_controle
        WHERE mesa = :mesa
        LIMIT 1
    ");
    $stmt->execute([":mesa" => $mesa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function buscarConflitoRota(PDO $pdo, string $mesaAtual, string $rotaTexto): ?array {
    $stmt = $pdo->prepare("
        SELECT
            mesa,
            driver_db_id,
            driver_id,
            rota_texto,
            status_mesa,
            updated_at
        FROM mesas_controle
        WHERE rota_texto = :rota_texto
          AND mesa <> :mesa
          AND status_mesa = 'conferindo'
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ":rota_texto" => $rotaTexto,
        ":mesa" => $mesaAtual
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function buscarMesasMesmaRota(PDO $pdo, string $rotaTexto): array {
    $stmt = $pdo->prepare("
        SELECT
            mesa,
            driver_db_id,
            driver_id,
            rota_texto,
            status_mesa,
            updated_at
        FROM mesas_controle
        WHERE rota_texto = :rota_texto
        ORDER BY
            CASE WHEN status_mesa = 'conferindo' THEN 0 ELSE 1 END,
            updated_at DESC,
            mesa ASC
    ");
    $stmt->execute([
        ":rota_texto" => $rotaTexto
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function upsertMesaControle(
    PDO $pdo,
    string $mesa,
    ?string $driverDbId,
    ?string $driverId,
    ?string $rotaTexto,
    string $statusMesa
): void {
    $stmt = $pdo->prepare("
        INSERT INTO mesas_controle
        (
            mesa,
            driver_db_id,
            driver_id,
            rota_texto,
            status_mesa,
            updated_at
        )
        VALUES
        (
            :mesa,
            :driver_db_id,
            :driver_id,
            :rota_texto,
            :status_mesa,
            NOW()
        )
        ON CONFLICT (mesa)
        DO UPDATE SET
            driver_db_id = EXCLUDED.driver_db_id,
            driver_id = EXCLUDED.driver_id,
            rota_texto = EXCLUDED.rota_texto,
            status_mesa = EXCLUDED.status_mesa,
            updated_at = NOW()
    ");

    $stmt->execute([
        ":mesa" => $mesa,
        ":driver_db_id" => ($driverDbId !== null && $driverDbId !== "") ? $driverDbId : null,
        ":driver_id" => ($driverId !== null && $driverId !== "") ? $driverId : null,
        ":rota_texto" => ($rotaTexto !== null && $rotaTexto !== "") ? $rotaTexto : null,
        ":status_mesa" => statusMesaValido($statusMesa)
    ]);
}

try {
    if ($action === "") {
        responder([
            "ok" => false,
            "erro" => "Ação não informada."
        ], 400);
    }

    /* =========================================
       SALVAR PESQUISA DA MESA
    ========================================= */
    if ($action === "save_search") {
        $mesa = limparTexto($_POST["mesa"] ?? "");
        $driverDbId = limparTexto($_POST["driver_db_id"] ?? "");
        $driverId = limparTexto($_POST["driver_id"] ?? "");
        $rotaTexto = limparTexto($_POST["rota_texto"] ?? "");

        if ($mesa === "") {
            responder([
                "ok" => false,
                "erro" => "Mesa inválida."
            ], 400);
        }

        upsertMesaControle(
            $pdo,
            $mesa,
            $driverDbId !== "" ? $driverDbId : null,
            $driverId !== "" ? $driverId : null,
            $rotaTexto !== "" ? $rotaTexto : null,
            "pesquisado"
        );

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);

        responder([
            "ok" => true,
            "mensagem" => "Pesquisa salva na mesa com sucesso.",
            "mesa" => $mesaAtual
        ]);
    }

    /* =========================================
       MARCAR MESA COMO CONFERINDO
    ========================================= */
    if ($action === "set_conferindo") {
        $mesa = limparTexto($_POST["mesa"] ?? "");
        $driverDbId = limparTexto($_POST["driver_db_id"] ?? "");
        $driverId = limparTexto($_POST["driver_id"] ?? "");
        $rotaTexto = limparTexto($_POST["rota_texto"] ?? "");

        if ($mesa === "" || $rotaTexto === "") {
            responder([
                "ok" => false,
                "erro" => "Mesa ou rota inválida."
            ], 400);
        }

        $conflito = buscarConflitoRota($pdo, $mesa, $rotaTexto);

        if ($conflito) {
            responder([
                "ok" => false,
                "conflito" => true,
                "mesa_conflito" => $conflito["mesa"],
                "driver_id_conflito" => $conflito["driver_id"],
                "status_mesa_conflito" => $conflito["status_mesa"],
                "mensagem" => "Essa rota já está em conferência na mesa {$conflito["mesa"]}" .
                    (!empty($conflito["driver_id"]) ? " pelo ID {$conflito["driver_id"]}." : ".")
            ], 200);
        }

        upsertMesaControle(
            $pdo,
            $mesa,
            $driverDbId !== "" ? $driverDbId : null,
            $driverId !== "" ? $driverId : null,
            $rotaTexto,
            "conferindo"
        );

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);
        $mesmasRotas = buscarMesasMesmaRota($pdo, $rotaTexto);

        responder([
            "ok" => true,
            "mensagem" => "Mesa marcada como conferindo.",
            "mesa" => $mesaAtual,
            "mesas_mesma_rota" => $mesmasRotas
        ]);
    }

    /* =========================================
       CHECAR CONFLITO DE ROTA
    ========================================= */
    if ($action === "check_conflict") {
        $mesa = limparTexto($_GET["mesa"] ?? $_POST["mesa"] ?? "");
        $rotaTexto = limparTexto($_GET["rota_texto"] ?? $_POST["rota_texto"] ?? "");

        if ($mesa === "" || $rotaTexto === "") {
            responder([
                "ok" => false,
                "erro" => "Mesa ou rota inválida."
            ], 400);
        }

        $conflito = buscarConflitoRota($pdo, $mesa, $rotaTexto);
        $mesmasRotas = buscarMesasMesmaRota($pdo, $rotaTexto);

        if ($conflito) {
            responder([
                "ok" => true,
                "conflito" => true,
                "mesa_conflito" => $conflito["mesa"],
                "driver_id_conflito" => $conflito["driver_id"],
                "status_mesa_conflito" => $conflito["status_mesa"],
                "mesas_mesma_rota" => $mesmasRotas,
                "mensagem" => "Essa rota já está em conferência na mesa {$conflito["mesa"]}" .
                    (!empty($conflito["driver_id"]) ? " pelo ID {$conflito["driver_id"]}." : ".")
            ]);
        }

        responder([
            "ok" => true,
            "conflito" => false,
            "mesas_mesma_rota" => $mesmasRotas,
            "mensagem" => "Nenhum conflito encontrado para essa rota."
        ]);
    }

    /* =========================================
       LIMPAR MESA
    ========================================= */
    if ($action === "clear") {
        $mesa = limparTexto($_POST["mesa"] ?? $_GET["mesa"] ?? "");

        if ($mesa === "") {
            responder([
                "ok" => false,
                "erro" => "Mesa inválida."
            ], 400);
        }

        $stmt = $pdo->prepare("
            DELETE FROM mesas_controle
            WHERE mesa = :mesa
        ");
        $stmt->execute([
            ":mesa" => $mesa
        ]);

        responder([
            "ok" => true,
            "mensagem" => "Mesa limpa com sucesso.",
            "mesa" => $mesa
        ]);
    }

    /* =========================================
       STATUS ATUAL DE UMA MESA
    ========================================= */
    if ($action === "get_mesa") {
        $mesa = limparTexto($_GET["mesa"] ?? $_POST["mesa"] ?? "");

        if ($mesa === "") {
            responder([
                "ok" => false,
                "erro" => "Mesa inválida."
            ], 400);
        }

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);

        responder([
            "ok" => true,
            "mesa" => $mesaAtual,
            "existe" => $mesaAtual ? true : false
        ]);
    }

    /* =========================================
       LISTAR MESAS DA MESMA ROTA
    ========================================= */
    if ($action === "route_watch") {
        $rotaTexto = limparTexto($_GET["rota_texto"] ?? $_POST["rota_texto"] ?? "");

        if ($rotaTexto === "") {
            responder([
                "ok" => false,
                "erro" => "Rota inválida."
            ], 400);
        }

        $mesmasRotas = buscarMesasMesmaRota($pdo, $rotaTexto);

        responder([
            "ok" => true,
            "rota_texto" => $rotaTexto,
            "mesas" => $mesmasRotas
        ]);
    }

    responder([
        "ok" => false,
        "erro" => "Ação inválida."
    ], 400);

} catch (Throwable $e) {
    responder([
        "ok" => false,
        "erro" => $e->getMessage()
    ], 500);
}