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
    return in_array($status, ["pesquisado", "conferindo"], true) ? $status : "pesquisado";
}

function buscarMesaPorNumero(PDO $pdo, string $mesa): ?array {
    $stmt = $pdo->prepare("
        SELECT mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at
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
        SELECT mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at
        FROM mesas_controle
        WHERE rota_texto = :rota_texto
          AND mesa <> :mesa
          AND status_mesa = 'conferindo'
        ORDER BY updated_at DESC, mesa ASC
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
        SELECT mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at
        FROM mesas_controle
        WHERE rota_texto = :rota_texto
        ORDER BY
            CASE WHEN status_mesa = 'conferindo' THEN 0 ELSE 1 END,
            updated_at DESC,
            mesa ASC
    ");
    $stmt->execute([":rota_texto" => $rotaTexto]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function upsertMesaControle(PDO $pdo, string $mesa, ?string $driverDbId, ?string $driverId, ?string $rotaTexto, string $statusMesa): void {
    $stmt = $pdo->prepare("
        INSERT INTO mesas_controle (mesa, driver_db_id, driver_id, rota_texto, status_mesa, updated_at)
        VALUES (:mesa, :driver_db_id, :driver_id, :rota_texto, :status_mesa, NOW())
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

function parseMotoFormulaPhp(?string $formula): array {
    $formula = trim((string)$formula);
    $resultado = [];
    if ($formula === "") return $resultado;

    preg_match_all('/([A-Z]-\d+)\((\d+)\)/i', $formula, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $resultado[] = [
            "cluster_code" => trim((string)$m[1]),
            "packages" => (int)$m[2]
        ];
    }
    return $resultado;
}

function carregarDriverPorId(PDO $pdo, string $driverId): ?array {
    $stmt = $pdo->prepare("
        SELECT d.id, d.driver_id, d.driver_name, d.cluster_text, d.packages_total,
               d.vehicle_type, d.status, d.moto_formula,
               dc.cluster_code, dc.packages AS cluster_packages, dc.sort_order
        FROM drivers d
        LEFT JOIN driver_clusters dc ON dc.driver_ref = d.id
        WHERE d.active = true AND d.driver_id = :driver_id
        ORDER BY dc.sort_order ASC, dc.id ASC
    ");
    $stmt->execute([":driver_id" => $driverId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    $base = $rows[0];
    $driver = [
        "id" => (int)$base["id"],
        "driver_id" => (string)$base["driver_id"],
        "driver_name" => (string)$base["driver_name"],
        "cluster_text" => (string)$base["cluster_text"],
        "packages_total" => (int)$base["packages_total"],
        "vehicle_type" => (string)$base["vehicle_type"],
        "status" => (string)$base["status"],
        "moto_formula" => (string)($base["moto_formula"] ?? ""),
        "moto_items" => parseMotoFormulaPhp($base["moto_formula"] ?? ""),
        "clusters" => []
    ];

    foreach ($rows as $row) {
        if (!empty($row["cluster_code"])) {
            $driver["clusters"][] = [
                "cluster_code" => (string)$row["cluster_code"],
                "packages" => (int)$row["cluster_packages"],
                "sort_order" => (int)$row["sort_order"]
            ];
        }
    }

    return $driver;
}

function carregarCompanheirosDaRota(PDO $pdo, string $clusterText): array {
    $stmt = $pdo->prepare("
        SELECT d.id, d.driver_id, d.driver_name, d.cluster_text, d.packages_total,
               d.vehicle_type, d.status,
               mc.mesa AS mesa_atual, mc.status_mesa
        FROM drivers d
        LEFT JOIN mesas_controle mc ON mc.driver_id = d.driver_id
        WHERE d.active = true AND d.cluster_text = :cluster_text
        ORDER BY d.driver_name ASC
    ");
    $stmt->execute([":cluster_text" => $clusterText]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    if ($action === "") {
        responder(["ok" => false, "erro" => "Ação não informada."], 400);
    }

    if ($action === "save_search") {
        $mesa = limparTexto($_POST["mesa"] ?? "");
        $driverDbId = limparTexto($_POST["driver_db_id"] ?? "");
        $driverId = limparTexto($_POST["driver_id"] ?? "");
        $rotaTexto = limparTexto($_POST["rota_texto"] ?? "");

        if ($mesa === "") responder(["ok" => false, "erro" => "Mesa inválida."], 400);

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);
        if ($mesaAtual && ($mesaAtual["status_mesa"] ?? "") === "conferindo" && !empty($mesaAtual["driver_id"]) && $mesaAtual["driver_id"] !== $driverId) {
            responder([
                "ok" => false,
                "bloqueado" => true,
                "mensagem" => "A mesa {$mesa} está com o ID {$mesaAtual['driver_id']} em conferência. Finalize antes de pesquisar outro motorista.",
                "mesa" => $mesaAtual
            ], 200);
        }

        upsertMesaControle($pdo, $mesa, $driverDbId !== "" ? $driverDbId : null, $driverId !== "" ? $driverId : null, $rotaTexto !== "" ? $rotaTexto : null, $mesaAtual["status_mesa"] ?? "pesquisado");
        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);

        responder([
            "ok" => true,
            "mensagem" => "Pesquisa salva na mesa com sucesso.",
            "mesa" => $mesaAtual
        ]);
    }

    if ($action === "set_conferindo") {
        $mesa = limparTexto($_POST["mesa"] ?? "");
        $driverDbId = limparTexto($_POST["driver_db_id"] ?? "");
        $driverId = limparTexto($_POST["driver_id"] ?? "");
        $rotaTexto = limparTexto($_POST["rota_texto"] ?? "");

        if ($mesa === "" || $rotaTexto === "") responder(["ok" => false, "erro" => "Mesa ou rota inválida."], 400);

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);
        if ($mesaAtual && ($mesaAtual["status_mesa"] ?? "") === "conferindo") {
            if (($mesaAtual["driver_id"] ?? "") === $driverId) {
                responder([
                    "ok" => true,
                    "ja_estava_conferindo" => true,
                    "mensagem" => "Esse motorista já está em conferência nesta mesa.",
                    "mesa" => $mesaAtual,
                    "mesas_mesma_rota" => buscarMesasMesmaRota($pdo, $rotaTexto)
                ]);
            }

            responder([
                "ok" => false,
                "bloqueado" => true,
                "mensagem" => "A mesa {$mesa} já está em conferência com o ID {$mesaAtual['driver_id']}. Finalize antes de trocar.",
                "mesa" => $mesaAtual
            ], 200);
        }

        $conflito = buscarConflitoRota($pdo, $mesa, $rotaTexto);
        if ($conflito) {
            responder([
                "ok" => false,
                "conflito" => true,
                "mesa_conflito" => $conflito["mesa"],
                "driver_id_conflito" => $conflito["driver_id"],
                "status_mesa_conflito" => $conflito["status_mesa"],
                "mensagem" => "Essa rota já está em conferência na mesa {$conflito['mesa']}" . (!empty($conflito["driver_id"]) ? " pelo ID {$conflito['driver_id']}." : ".")
            ], 200);
        }

        upsertMesaControle($pdo, $mesa, $driverDbId !== "" ? $driverDbId : null, $driverId !== "" ? $driverId : null, $rotaTexto, "conferindo");
        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);

        responder([
            "ok" => true,
            "mensagem" => "Mesa marcada como conferindo.",
            "mesa" => $mesaAtual,
            "mesas_mesma_rota" => buscarMesasMesmaRota($pdo, $rotaTexto)
        ]);
    }

    if ($action === "check_conflict") {
        $mesa = limparTexto($_GET["mesa"] ?? $_POST["mesa"] ?? "");
        $rotaTexto = limparTexto($_GET["rota_texto"] ?? $_POST["rota_texto"] ?? "");

        if ($mesa === "" || $rotaTexto === "") responder(["ok" => false, "erro" => "Mesa ou rota inválida."], 400);

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
                "mensagem" => "Essa rota já está em conferência na mesa {$conflito['mesa']}" . (!empty($conflito["driver_id"]) ? " pelo ID {$conflito['driver_id']}." : ".")
            ]);
        }

        responder([
            "ok" => true,
            "conflito" => false,
            "mesas_mesma_rota" => $mesmasRotas,
            "mensagem" => "Nenhum conflito encontrado para essa rota."
        ]);
    }

    if ($action === "clear") {
        $mesa = limparTexto($_POST["mesa"] ?? $_GET["mesa"] ?? "");
        if ($mesa === "") responder(["ok" => false, "erro" => "Mesa inválida."], 400);

        $stmt = $pdo->prepare("DELETE FROM mesas_controle WHERE mesa = :mesa");
        $stmt->execute([":mesa" => $mesa]);

        responder([
            "ok" => true,
            "mensagem" => "Mesa limpa com sucesso.",
            "mesa" => $mesa
        ]);
    }

    if ($action === "get_mesa") {
        $mesa = limparTexto($_GET["mesa"] ?? $_POST["mesa"] ?? "");
        if ($mesa === "") responder(["ok" => false, "erro" => "Mesa inválida."], 400);

        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);
        responder([
            "ok" => true,
            "mesa" => $mesaAtual,
            "existe" => $mesaAtual ? true : false
        ]);
    }

    if ($action === "route_watch") {
        $rotaTexto = limparTexto($_GET["rota_texto"] ?? $_POST["rota_texto"] ?? "");
        if ($rotaTexto === "") responder(["ok" => false, "erro" => "Rota inválida."], 400);

        responder([
            "ok" => true,
            "rota_texto" => $rotaTexto,
            "mesas" => buscarMesasMesmaRota($pdo, $rotaTexto)
        ]);
    }

    if ($action === "status_live") {
        $mesa = limparTexto($_GET["mesa"] ?? $_POST["mesa"] ?? "");
        $driverId = limparTexto($_GET["driver_id"] ?? $_POST["driver_id"] ?? "");
        if ($mesa === "" || $driverId === "") responder(["ok" => false, "erro" => "Mesa ou driver_id inválido."], 400);

        $driver = carregarDriverPorId($pdo, $driverId);
        if (!$driver) {
            responder([
                "ok" => true,
                "found" => false,
                "mesa" => buscarMesaPorNumero($pdo, $mesa)
            ]);
        }

        $companheiros = carregarCompanheirosDaRota($pdo, (string)$driver["cluster_text"]);
        $mesaAtual = buscarMesaPorNumero($pdo, $mesa);
        $conflito = buscarConflitoRota($pdo, $mesa, (string)$driver["cluster_text"]);

        responder([
            "ok" => true,
            "found" => true,
            "mesa" => $mesaAtual,
            "driver" => $driver,
            "companheiros" => $companheiros,
            "conflito_rota" => $conflito,
            "mesas_mesma_rota" => buscarMesasMesmaRota($pdo, (string)$driver["cluster_text"])
        ]);
    }

    responder(["ok" => false, "erro" => "Ação inválida."], 400);
} catch (Throwable $e) {
    responder(["ok" => false, "erro" => $e->getMessage()], 500);
}
