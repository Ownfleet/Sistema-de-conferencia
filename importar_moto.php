<?php
require "db.php";


if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

if (!isset($_FILES["arquivo"]) || $_FILES["arquivo"]["error"] !== 0) {
    die("Arquivo não enviado");
}

$tmp = $_FILES["arquivo"]["tmp_name"];
$handle = fopen($tmp, "r");

if (!$handle) {
    die("Erro ao abrir CSV");
}

function parseClusterTotalMoto($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return [
            "cluster_text" => "",
            "packages_total" => 0
        ];
    }

    if (strpos($value, "/") !== false) {
        [$clusterPart, $packagesPart] = explode("/", $value, 2);

        return [
            "cluster_text" => trim($clusterPart),
            "packages_total" => (int) preg_replace("/\D/", "", $packagesPart)
        ];
    }

    return [
        "cluster_text" => $value,
        "packages_total" => 0
    ];
}

function parseFormulaMoto($formula) {
    $formula = trim((string)$formula);
    $resultado = [];

    if ($formula === "") {
        return $resultado;
    }

    preg_match_all('/([A-Z]-\d+)\((\d+)\)/', $formula, $matches, PREG_SET_ORDER);

    $ordem = 1;
    foreach ($matches as $m) {
        $resultado[] = [
            "cluster_code" => trim($m[1]),
            "packages" => (int)$m[2],
            "sort_order" => $ordem++
        ];
    }

    return $resultado;
}

try {
    $pdo->beginTransaction();

    $stmtImport = $pdo->prepare("
        insert into imports (import_type, imported_by, is_active)
        values ('moto', ?, true)
        returning id
    ");
    $stmtImport->execute([$_SESSION["user"]["email"]]);
    $importId = $stmtImport->fetchColumn();

    $stmtDriver = $pdo->prepare("
        insert into drivers
        (import_id, driver_id, driver_name, cluster_text, packages_total, vehicle_type, moto_formula, status, active)
        values (?, ?, ?, ?, ?, ?, ?, 'ativo', true)
        returning id
    ");

    $stmtCluster = $pdo->prepare("
        insert into driver_clusters (driver_ref, cluster_code, packages, sort_order)
        values (?, ?, ?, ?)
    ");

    $linha = 0;

    while (($data = fgetcsv($handle, 0, ",")) !== false) {
        if ($linha === 0) {
            $linha++;
            continue;
        }

        $driver_id    = trim((string)($data[0] ?? ""));
        $driver_name  = trim((string)($data[1] ?? ""));
        $cluster_raw  = trim((string)($data[2] ?? "")); // coluna C
        $formula_raw  = trim((string)($data[3] ?? "")); // coluna D

        if ($driver_id === "" || $driver_name === "" || $cluster_raw === "") {
            $linha++;
            continue;
        }

        $clusterInfo = parseClusterTotalMoto($cluster_raw);
        $formulaInfo = parseFormulaMoto($formula_raw);

        $stmtDriver->execute([
            $importId,
            $driver_id,
            $driver_name,
            $clusterInfo["cluster_text"],
            $clusterInfo["packages_total"],
            "MOTO",
            $formula_raw
        ]);

        $driverRef = $stmtDriver->fetchColumn();

        if (count($formulaInfo) > 0) {
            foreach ($formulaInfo as $item) {
                $stmtCluster->execute([
                    $driverRef,
                    $item["cluster_code"],
                    $item["packages"],
                    $item["sort_order"]
                ]);
            }
        } else {
            $clusters = array_values(array_filter(array_map("trim", explode("+", $clusterInfo["cluster_text"]))));
            $ordem = 1;

            foreach ($clusters as $clusterCode) {
                $stmtCluster->execute([
                    $driverRef,
                    $clusterCode,
                    0,
                    $ordem++
                ]);
            }
        }

        $linha++;
    }

    fclose($handle);
    $pdo->commit();

    header("Location: admin.php");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die("Erro ao importar moto: " . $e->getMessage());
}