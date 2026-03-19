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
    die("Erro ao enviar arquivo.");
}

function parseClusterAndPackages($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return [
            "cluster_text" => "",
            "packages_total" => 0,
            "clusters" => []
        ];
    }

    if (strpos($value, "/") !== false) {
        [$clusterPart, $packagesPart] = explode("/", $value, 2);
        $packages = (int) preg_replace("/\D/", "", $packagesPart);
    } else {
        $clusterPart = $value;
        $packages = 0;
    }

    $clusters = array_values(array_filter(array_map("trim", explode("+", $clusterPart))));

    return [
        "cluster_text" => trim($clusterPart),
        "packages_total" => $packages,
        "clusters" => $clusters
    ];
}

try {
    $arquivo = $_FILES["arquivo"]["tmp_name"];

    if (!is_uploaded_file($arquivo)) {
        die("Arquivo inválido.");
    }

    $handle = fopen($arquivo, "r");

    if ($handle === false) {
        die("Não foi possível abrir o CSV.");
    }

    $pdo->beginTransaction();

    $stmtImport = $pdo->prepare("
        insert into imports (import_type, imported_by, is_active)
        values ('normal', ?, true)
        returning id
    ");
    $stmtImport->execute([$_SESSION["user"]["email"]]);
    $importId = $stmtImport->fetchColumn();

    $stmtDriver = $pdo->prepare("
        insert into drivers
        (import_id, driver_id, driver_name, cluster_text, packages_total, vehicle_type, status, active)
        values (?, ?, ?, ?, ?, ?, 'ativo', true)
        returning id
    ");

    $stmtCluster = $pdo->prepare("
        insert into driver_clusters (driver_ref, cluster_code, packages, sort_order)
        values (?, ?, ?, ?)
    ");

    $linha = 0;

    while (($dados = fgetcsv($handle, 0, ",")) !== false) {
        if ($linha === 0) {
            $linha++;
            continue;
        }

        $driverId   = trim((string)($dados[0] ?? ""));
        $driverName = trim((string)($dados[1] ?? ""));
        $clusterRaw = trim((string)($dados[2] ?? ""));
        $vehicle    = trim((string)($dados[3] ?? ""));

        if ($driverId === "" || $driverName === "" || $clusterRaw === "") {
            $linha++;
            continue;
        }

        $parsed = parseClusterAndPackages($clusterRaw);

        $stmtDriver->execute([
            $importId,
            $driverId,
            $driverName,
            $parsed["cluster_text"],
            $parsed["packages_total"],
            $vehicle
        ]);

        $driverRef = $stmtDriver->fetchColumn();

        $ordem = 1;
        foreach ($parsed["clusters"] as $clusterCode) {
            $stmtCluster->execute([
                $driverRef,
                $clusterCode,
                0,
                $ordem++
            ]);
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

    die("Erro ao importar: " . $e->getMessage());
}