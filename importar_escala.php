<?php
require "db.php";

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION["user"]["role"] ?? "") !== "admin") {
    die("Acesso negado");
}

if (
    !isset($_FILES["arquivo_normal"]) || $_FILES["arquivo_normal"]["error"] !== 0 ||
    !isset($_FILES["arquivo_moto"]) || $_FILES["arquivo_moto"]["error"] !== 0
) {
    die("Envie os dois arquivos: normal e moto.");
}

function normalizarCabecalho(string $texto): string {
    $texto = trim(mb_strtolower($texto, 'UTF-8'));
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    $texto = preg_replace('/[^a-z0-9]+/', '_', $texto);
    return trim($texto, '_');
}

function mapearCabecalhos(array $header): array {
    $mapa = [];
    foreach ($header as $i => $coluna) {
        $mapa[normalizarCabecalho((string)$coluna)] = $i;
    }
    return $mapa;
}

function pegarValor(array $linha, array $mapa, array $aliases, string $padrao = ""): string {
    foreach ($aliases as $alias) {
        $chave = normalizarCabecalho($alias);
        if (isset($mapa[$chave])) {
            $idx = $mapa[$chave];
            return trim((string)($linha[$idx] ?? $padrao));
        }
    }
    return $padrao;
}

function parseClusterNormal(string $value): array {
    $value = trim($value);

    if ($value === "") {
        return [
            "cluster_text" => "",
            "packages_total" => 0,
            "clusters" => []
        ];
    }

    $clusterPart = $value;
    $packages = 0;

    if (strpos($value, "/") !== false) {
        [$clusterPart, $packagesPart] = explode("/", $value, 2);
        $packages = (int)preg_replace("/\D/", "", $packagesPart);
    }

    $clusters = array_values(array_filter(array_map("trim", explode("+", $clusterPart))));

    return [
        "cluster_text" => trim($clusterPart),
        "packages_total" => $packages,
        "clusters" => $clusters
    ];
}

function distribuirPacotesNormal(array $clusters, int $packagesTotal): array {
    $resultado = [];
    $qtd = count($clusters);

    if ($qtd <= 0) return $resultado;

    $base = $packagesTotal > 0 ? (int)floor($packagesTotal / $qtd) : 0;
    $resto = $packagesTotal > 0 ? $packagesTotal - ($base * $qtd) : 0;

    foreach ($clusters as $cluster) {
        $pacotes = $base;
        if ($resto > 0) {
            $pacotes++;
            $resto--;
        }

        $resultado[] = [
            "cluster_code" => $cluster,
            "packages" => $pacotes
        ];
    }

    return $resultado;
}

function parseMotoFormula(string $formula): array {
    $formula = trim($formula);
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

function montarMotoFormula(array $clusters): string {
    $partes = [];

    foreach ($clusters as $clusterItem) {
        $cluster = trim((string)($clusterItem["cluster_code"] ?? ""));
        $packages = (int)($clusterItem["packages"] ?? 0);

        if ($cluster === "") {
            continue;
        }

        $partes[] = $cluster . "(" . $packages . ")";
    }

    return implode("+", $partes);
}

function somarPacotesClusters(array $clusters): int {
    $total = 0;

    foreach ($clusters as $clusterItem) {
        $total += (int)($clusterItem["packages"] ?? 0);
    }

    return $total;
}

function normalizarRotaComparacao(string $rota): string {
    $rota = strtoupper(trim($rota));
    $rota = preg_replace('/\s+/', '', $rota);
    return $rota;
}

function tabelaExiste(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
        )
    ");
    $stmt->execute([":table_name" => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function deletarSeExistir(PDO $pdo, string $tableName): void {
    if (tabelaExiste($pdo, $tableName)) {
        $pdo->exec('DELETE FROM "' . $tableName . '"');
    }
}

$arquivoNormal = $_FILES["arquivo_normal"]["tmp_name"];
$arquivoMoto = $_FILES["arquivo_moto"]["tmp_name"];

if (!is_uploaded_file($arquivoNormal) || !is_uploaded_file($arquivoMoto)) {
    die("Arquivos inválidos.");
}

$handleNormal = fopen($arquivoNormal, "r");
$handleMoto = fopen($arquivoMoto, "r");

if ($handleNormal === false || $handleMoto === false) {
    die("Não foi possível abrir um dos arquivos CSV.");
}

try {
    $pdo->beginTransaction();

    // Limpeza segura
    deletarSeExistir($pdo, "driver_clusters");
    deletarSeExistir($pdo, "drivers");
    if (tabelaExiste($pdo, "imports")) {
        $pdo->exec("UPDATE imports SET is_active = false WHERE is_active = true");
    }
    deletarSeExistir($pdo, "mesa_controle");
    deletarSeExistir($pdo, "mesas_controle");
    deletarSeExistir($pdo, "mesa_tempos");

    // Cria um único import ativo
    $stmtImport = $pdo->prepare("
        INSERT INTO imports (import_type, imported_by, is_active, created_at)
        VALUES ('normal', ?, true, NOW())
        RETURNING id
    ");
    $stmtImport->execute([$_SESSION["user"]["email"] ?? "admin"]);
    $importId = $stmtImport->fetchColumn();

    $stmtDriver = $pdo->prepare("
        INSERT INTO drivers
        (
            import_id,
            driver_id,
            driver_name,
            cluster_text,
            packages_total,
            vehicle_type,
            status,
            active,
            moto_formula
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, 'ativo', true, ?
        )
        RETURNING id
    ");

    $stmtCluster = $pdo->prepare("
        INSERT INTO driver_clusters
        (
            driver_ref,
            cluster_code,
            packages,
            sort_order
        )
        VALUES
        (
            ?, ?, ?, ?
        )
    ");

    /* =========================
       IMPORTAÇÃO NORMAL
    ========================= */
    $headerNormal = fgetcsv($handleNormal, 0, ",");
    if ($headerNormal === false) {
        throw new Exception("Arquivo normal vazio.");
    }

    $mapaNormal = mapearCabecalhos($headerNormal);

    while (($linha = fgetcsv($handleNormal, 0, ",")) !== false) {
        $driverId   = pegarValor($linha, $mapaNormal, ["driver_id", "id_motorista", "id"]);
        $driverName = pegarValor($linha, $mapaNormal, ["driver_name", "nome_motorista", "motorista", "nome"]);
        $clusterRaw = pegarValor($linha, $mapaNormal, ["cluster", "cluster_text", "rota", "aglomerado"]);
        $vehicle    = strtoupper(pegarValor($linha, $mapaNormal, ["vehicle_type", "tipo_veiculo", "veiculo"], "PASSEIO"));

        if ($driverId === "" || $driverName === "" || $clusterRaw === "") {
            continue;
        }

        $parsed = parseClusterNormal($clusterRaw);
        $clustersDistribuidos = distribuirPacotesNormal($parsed["clusters"], (int)$parsed["packages_total"]);

        $stmtDriver->execute([
            $importId,
            $driverId,
            $driverName,
            $parsed["cluster_text"],
            $parsed["packages_total"],
            $vehicle,
            null
        ]);

        $driverRef = $stmtDriver->fetchColumn();

        $ordem = 1;
        foreach ($clustersDistribuidos as $clusterItem) {
            $stmtCluster->execute([
                $driverRef,
                $clusterItem["cluster_code"],
                $clusterItem["packages"],
                $ordem++
            ]);
        }
    }

    /* =========================
       IMPORTAÇÃO MOTO
       COM DIVISÃO AUTOMÁTICA
       QUANDO A MESMA ROTA
       APARECER PARA MAIS DE 1 MOTO
    ========================= */
    $headerMoto = fgetcsv($handleMoto, 0, ",");
    if ($headerMoto === false) {
        throw new Exception("Arquivo moto vazio.");
    }

    $mapaMoto = mapearCabecalhos($headerMoto);
    $motosImportadas = [];

    while (($linha = fgetcsv($handleMoto, 0, ",")) !== false) {
        $driverId    = pegarValor($linha, $mapaMoto, ["id", "driver_id", "id_motorista"]);
        $driverName  = pegarValor($linha, $mapaMoto, ["nome", "nome_do_motorista", "driver_name", "nome_motorista"]);
        $clusterBruto = pegarValor($linha, $mapaMoto, ["cluster", "cluster_text", "rota"]);
        $motoFormula = pegarValor($linha, $mapaMoto, ["contem_formula", "moto_formula", "formula_moto"], "");
        $vehicleType = "MOTO";

        if ($driverId === "" || $driverName === "" || $clusterBruto === "") {
            continue;
        }

        $parsedMotoCluster = parseClusterNormal($clusterBruto);
        $clusterText = $parsedMotoCluster["cluster_text"];
        $clustersMoto = parseMotoFormula($motoFormula);

        // Se não veio fórmula mas veio /qtd, tenta usar cluster único
        if (count($clustersMoto) === 0 && $clusterText !== "" && (int)$parsedMotoCluster["packages_total"] > 0) {
            $clustersMoto[] = [
                "cluster_code" => $clusterText,
                "packages" => (int)$parsedMotoCluster["packages_total"]
            ];
        }

        $motosImportadas[] = [
            "driver_id" => $driverId,
            "driver_name" => $driverName,
            "cluster_text" => $clusterText,
            "vehicle_type" => $vehicleType,
            "clusters_moto" => $clustersMoto,
            "moto_formula_original" => $motoFormula
        ];
    }

    // Conta quantas motos existem na mesma rota
    $contagemPorRota = [];

    foreach ($motosImportadas as $moto) {
        $rotaKey = normalizarRotaComparacao($moto["cluster_text"]);
        if ($rotaKey === "") continue;

        if (!isset($contagemPorRota[$rotaKey])) {
            $contagemPorRota[$rotaKey] = 0;
        }

        $contagemPorRota[$rotaKey]++;
    }

    // Salva já com a divisão aplicada
    foreach ($motosImportadas as $moto) {
        $rotaKey = normalizarRotaComparacao($moto["cluster_text"]);
        $quantidadeNaMesmaRota = (int)($contagemPorRota[$rotaKey] ?? 1);

        $clustersMotoAjustados = [];

        foreach ($moto["clusters_moto"] as $clusterItem) {
            $clusterCode = trim((string)($clusterItem["cluster_code"] ?? ""));
            $packagesOriginal = (int)($clusterItem["packages"] ?? 0);

            if ($clusterCode === "") {
                continue;
            }

            $packagesAjustado = $quantidadeNaMesmaRota > 1
                ? (int)ceil($packagesOriginal / $quantidadeNaMesmaRota)
                : $packagesOriginal;

            $clustersMotoAjustados[] = [
                "cluster_code" => $clusterCode,
                "packages" => $packagesAjustado
            ];
        }

        $packagesTotalAjustado = somarPacotesClusters($clustersMotoAjustados);
        $motoFormulaAjustada = montarMotoFormula($clustersMotoAjustados);

        $stmtDriver->execute([
            $importId,
            $moto["driver_id"],
            $moto["driver_name"],
            $moto["cluster_text"],
            $packagesTotalAjustado,
            $moto["vehicle_type"],
            $motoFormulaAjustada !== "" ? $motoFormulaAjustada : null
        ]);

        $driverRef = $stmtDriver->fetchColumn();

        $ordem = 1;
        foreach ($clustersMotoAjustados as $clusterItem) {
            $stmtCluster->execute([
                $driverRef,
                $clusterItem["cluster_code"],
                $clusterItem["packages"],
                $ordem++
            ]);
        }
    }

    fclose($handleNormal);
    fclose($handleMoto);

    $pdo->commit();

    header("Location: admin.php");
    exit;

} catch (Throwable $e) {
    if (is_resource($handleNormal)) fclose($handleNormal);
    if (is_resource($handleMoto)) fclose($handleMoto);

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die("Erro ao importar escala completa: " . $e->getMessage());
}