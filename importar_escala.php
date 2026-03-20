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

    // limpa tudo uma única vez
    $pdo->exec("DELETE FROM driver_clusters");
    $pdo->exec("DELETE FROM drivers");
    $pdo->exec("UPDATE imports SET is_active = false WHERE is_active = true");
    $pdo->exec("DELETE FROM mesa_controle");
    $pdo->exec("DELETE FROM mesas_controle");
    $pdo->exec("DELETE FROM mesa_tempos");

    // cria um único import ativo
    $stmtImport = $pdo->prepare("
        INSERT INTO imports (import_type, imported_by, is_active, created_at)
        VALUES ('escala_completa', ?, true, NOW())
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
        $driverId = pegarValor($linha, $mapaNormal, ["driver_id", "id_motorista", "id"]);
        $driverName = pegarValor($linha, $mapaNormal, ["driver_name", "nome_motorista", "motorista", "nome"]);
        $clusterRaw = pegarValor($linha, $mapaNormal, ["cluster", "cluster_text", "rota", "aglomerado"]);
        $vehicle = pegarValor($linha, $mapaNormal, ["vehicle_type", "tipo_veiculo", "veiculo"], "PASSEIO");

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
    ========================= */
    $headerMoto = fgetcsv($handleMoto, 0, ",");
    if ($headerMoto === false) {
        throw new Exception("Arquivo moto vazio.");
    }

    $mapaMoto = mapearCabecalhos($headerMoto);

    while (($linha = fgetcsv($handleMoto, 0, ",")) !== false) {
        $driverId = pegarValor($linha, $mapaMoto, ["driver_id", "id_motorista", "id"]);
        $driverName = pegarValor($linha, $mapaMoto, ["driver_name", "nome_do_motorista", "nome_motorista", "motorista", "nome"]);
        $clusterText = pegarValor($linha, $mapaMoto, ["cluster_text", "texto_do_aglomerado", "cluster", "rota"]);
        $packagesTotal = (int)pegarValor($linha, $mapaMoto, ["packages_total", "pacotes_total", "pacotes"], "0");
        $vehicleType = pegarValor($linha, $mapaMoto, ["vehicle_type", "tipo_de_veiculo", "tipo_veiculo", "veiculo"], "MOTO");
        $motoFormula = pegarValor($linha, $mapaMoto, ["moto_formula", "formula_moto"], "");

        if ($driverId === "" || $driverName === "" || $clusterText === "") {
            continue;
        }

        $stmtDriver->execute([
            $importId,
            $driverId,
            $driverName,
            $clusterText,
            $packagesTotal,
            $vehicleType,
            $motoFormula !== "" ? $motoFormula : null
        ]);

        $driverRef = $stmtDriver->fetchColumn();

        $clustersMoto = parseMotoFormula($motoFormula);
        $ordem = 1;

        foreach ($clustersMoto as $clusterItem) {
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