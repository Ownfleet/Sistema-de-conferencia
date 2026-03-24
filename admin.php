<?php
require "db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION["user"]["role"] ?? "") !== "admin") {
    die("Acesso negado");
}

function filtroAtivo($valorAtual, $valorFiltro) {
    return $valorAtual === $valorFiltro ? "filtro-ativo" : "";
}

function formatarDuracao($segundos) {
    $segundos = (int)$segundos;
    if ($segundos <= 0) return "00:00:00";

    $h = floor($segundos / 3600);
    $m = floor(($segundos % 3600) / 60);
    $s = $segundos % 60;

    return str_pad((string)$h, 2, "0", STR_PAD_LEFT) . ":" .
           str_pad((string)$m, 2, "0", STR_PAD_LEFT) . ":" .
           str_pad((string)$s, 2, "0", STR_PAD_LEFT);
}

function formatarMinutosPorRota($segundos) {
    $segundos = (int)$segundos;
    if ($segundos <= 0) return "0 min";

    $min = floor($segundos / 60);
    $sec = $segundos % 60;

    if ($min <= 0) {
        return $sec . " seg";
    }

    return $min . " min " . str_pad((string)$sec, 2, "0", STR_PAD_LEFT) . " seg";
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

function somarPacotesMoto(array $clustersMoto): int {
    $total = 0;
    foreach ($clustersMoto as $item) {
        $total += (int)($item["packages"] ?? 0);
    }
    return $total;
}

function getOrCreateActiveImportId(PDO $pdo, string $tipo, string $email): int {
    $stmt = $pdo->query("SELECT id FROM imports WHERE is_active = true ORDER BY id DESC LIMIT 1");
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    $tipoPermitido = strtoupper($tipo) === "MOTO" ? "moto" : "normal";

    $stmtNovo = $pdo->prepare("
        INSERT INTO imports (import_type, imported_by, is_active, created_at)
        VALUES (?, ?, true, NOW())
        RETURNING id
    ");
    $stmtNovo->execute([$tipoPermitido, $email]);
    return (int)$stmtNovo->fetchColumn();
}

/* =========================
   FLASH
========================= */
$flashSuccess = $_SESSION["flash_success"] ?? "";
$flashError = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);

/* =========================
   CRIAR / ATUALIZAR ROTA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_route") {
    $driverId = trim((string)($_POST["driver_id_manual"] ?? ""));
    $driverName = trim((string)($_POST["driver_name_manual"] ?? ""));
    $vehicleType = strtoupper(trim((string)($_POST["vehicle_type_manual"] ?? "")));

    $rotaNormal = trim((string)($_POST["cluster_normal_manual"] ?? ""));
    $rotaMoto = trim((string)($_POST["cluster_moto_manual"] ?? ""));
    $formulaMoto = trim((string)($_POST["moto_formula_manual"] ?? ""));

    try {
        if ($driverId === "" || $driverName === "" || $vehicleType === "") {
            throw new Exception("Preencha ID, nome e veículo.");
        }

        $veiculosNormais = ["FIORINO", "PASSEIO", "ZOF-FIORINO-AR", "ZOF-PASSEIO-AR"];

        $pdo->beginTransaction();

        $importId = getOrCreateActiveImportId($pdo, $vehicleType, $_SESSION["user"]["email"] ?? "admin");

        $clusterText = "";
        $packagesTotal = 0;
        $motoFormula = null;
        $clustersParaSalvar = [];

        if ($vehicleType === "MOTO") {
            if ($rotaMoto === "" || $formulaMoto === "") {
                throw new Exception("Para MOTO, preencha rota exibida e fórmula moto.");
            }

            $clustersMoto = parseMotoFormula($formulaMoto);
            if (count($clustersMoto) === 0) {
                throw new Exception("A fórmula da moto está inválida. Exemplo: D-3(60)+D-4(55)");
            }

            $clusterText = $rotaMoto;
            $packagesTotal = somarPacotesMoto($clustersMoto);
            $motoFormula = $formulaMoto;
            $clustersParaSalvar = $clustersMoto;
        } else {
            if (!in_array($vehicleType, $veiculosNormais, true)) {
                throw new Exception("Veículo inválido para criação manual.");
            }

            if ($rotaNormal === "") {
                throw new Exception("Preencha a rota no padrão A-1+B-2/120.");
            }

            $parsed = parseClusterNormal($rotaNormal);

            if ($parsed["cluster_text"] === "") {
                throw new Exception("A rota normal está vazia.");
            }

            $clusterText = $parsed["cluster_text"];
            $packagesTotal = (int)$parsed["packages_total"];
            $clustersParaSalvar = distribuirPacotesNormal($parsed["clusters"], $packagesTotal);
        }

        $stmtExiste = $pdo->prepare("
            SELECT id
            FROM drivers
            WHERE active = true AND driver_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtExiste->execute([$driverId]);
        $driverExistenteId = $stmtExiste->fetchColumn();

        if ($driverExistenteId) {
            $driverExistenteId = (int)$driverExistenteId;

            $stmtDeleteClusters = $pdo->prepare("DELETE FROM driver_clusters WHERE driver_ref = ?");
            $stmtDeleteClusters->execute([$driverExistenteId]);

            $stmtUpdateDriver = $pdo->prepare("
                UPDATE drivers
                SET
                    import_id = ?,
                    driver_name = ?,
                    cluster_text = ?,
                    packages_total = ?,
                    vehicle_type = ?,
                    status = 'ativo',
                    moto_formula = ?,
                    active = true
                WHERE id = ?
            ");
            $stmtUpdateDriver->execute([
                $importId,
                $driverName,
                $clusterText,
                $packagesTotal,
                $vehicleType,
                $motoFormula,
                $driverExistenteId
            ]);

            $driverRef = $driverExistenteId;
        } else {
            $stmtInsertDriver = $pdo->prepare("
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
            $stmtInsertDriver->execute([
                $importId,
                $driverId,
                $driverName,
                $clusterText,
                $packagesTotal,
                $vehicleType,
                $motoFormula
            ]);

            $driverRef = (int)$stmtInsertDriver->fetchColumn();
        }

        $stmtCluster = $pdo->prepare("
            INSERT INTO driver_clusters (driver_ref, cluster_code, packages, sort_order)
            VALUES (?, ?, ?, ?)
        ");

        $ordem = 1;
        foreach ($clustersParaSalvar as $clusterItem) {
            $clusterCode = trim((string)($clusterItem["cluster_code"] ?? ""));
            $packages = (int)($clusterItem["packages"] ?? 0);

            if ($clusterCode === "") continue;

            $stmtCluster->execute([
                $driverRef,
                $clusterCode,
                $packages,
                $ordem++
            ]);
        }

        $pdo->commit();

        $_SESSION["flash_success"] = "Rota criada/atualizada com sucesso para o motorista {$driverName}.";
        header("Location: admin.php");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["flash_error"] = "Erro ao criar rota manual: " . $e->getMessage();
        header("Location: admin.php");
        exit;
    }
}

/* =========================
   EXCLUIR MOTORISTA / ROTA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_route") {
    $driverDeleteId = (int)($_POST["delete_driver_id"] ?? 0);

    try {
        if ($driverDeleteId <= 0) {
            throw new Exception("Motorista inválido para exclusão.");
        }

        $pdo->beginTransaction();

        $stmtBusca = $pdo->prepare("SELECT id, driver_name, driver_id FROM drivers WHERE id = ? LIMIT 1");
        $stmtBusca->execute([$driverDeleteId]);
        $driverDelete = $stmtBusca->fetch();

        if (!$driverDelete) {
            throw new Exception("Motorista não encontrado.");
        }

        $stmtMesaControle = $pdo->prepare("DELETE FROM mesa_controle WHERE driver_db_id = ?");
        $stmtMesaControle->execute([$driverDeleteId]);

        $stmtMesaTempos = $pdo->prepare("DELETE FROM mesa_tempos WHERE driver_ref = ?");
        $stmtMesaTempos->execute([$driverDeleteId]);

        $stmtClusters = $pdo->prepare("DELETE FROM driver_clusters WHERE driver_ref = ?");
        $stmtClusters->execute([$driverDeleteId]);

        $stmtDriver = $pdo->prepare("DELETE FROM drivers WHERE id = ?");
        $stmtDriver->execute([$driverDeleteId]);

        $pdo->commit();

        $_SESSION["flash_success"] = "Motorista {$driverDelete["driver_name"]} (ID: {$driverDelete["driver_id"]}) excluído com sucesso.";
        header("Location: admin.php");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["flash_error"] = "Erro ao excluir rota: " . $e->getMessage();
        header("Location: admin.php");
        exit;
    }
}

/* =========================
   LISTAGEM MOTORISTAS
========================= */
$filtroStatus = strtolower(trim($_GET["status"] ?? "todos"));

$sql = "
    SELECT *
    FROM drivers
    WHERE active = true
";

$params = [];

if (in_array($filtroStatus, ["ativo", "conferindo", "finalizado"], true)) {
    $sql .= " AND LOWER(status) = ?";
    $params[] = $filtroStatus;
}

$sql .= " ORDER BY driver_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drivers = $stmt->fetchAll();

/* =========================
   RESUMO
========================= */
$stmtResumo = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN LOWER(status) = 'ativo' THEN 1 ELSE 0 END) AS ativos,
        SUM(CASE WHEN LOWER(status) = 'conferindo' THEN 1 ELSE 0 END) AS conferindo,
        SUM(CASE WHEN LOWER(status) = 'finalizado' THEN 1 ELSE 0 END) AS finalizados
    FROM drivers
    WHERE active = true
");
$resumo = $stmtResumo->fetch();

$totalGeral = (int)($resumo["total"] ?? 0);
$totalAtivos = (int)($resumo["ativos"] ?? 0);
$totalConferindo = (int)($resumo["conferindo"] ?? 0);
$totalFinalizados = (int)($resumo["finalizados"] ?? 0);

/* =========================
   RELATÓRIO PARA CSV
========================= */
$stmtTempos = $pdo->query("
    SELECT
        mesa_numero,
        driver_ref,
        driver_id,
        driver_name,
        rota_texto,
        vehicle_type,
        started_at,
        finished_at,
        duration_seconds,
        status,
        created_at
    FROM mesa_tempos
    WHERE finished_at IS NOT NULL
    ORDER BY mesa_numero ASC, started_at ASC
");
$temposRows = $stmtTempos->fetchAll();

$relatorioMesas = [];
$totalRotasMesas = 0;
$totalSegundosMesas = 0;

foreach ($temposRows as $row) {
    $mesa = (int)($row["mesa_numero"] ?? 0);
    if ($mesa <= 0) continue;

    if (!isset($relatorioMesas[$mesa])) {
        $relatorioMesas[$mesa] = [
            "mesa_numero" => $mesa,
            "rotas_total" => 0,
            "segundos_total" => 0,
            "registros" => [],
            "rotas_unicas" => [],
            "motoristas_unicos" => []
        ];
    }

    $duracao = (int)($row["duration_seconds"] ?? 0);
    $rotaTexto = trim((string)($row["rota_texto"] ?? ""));
    $driverName = trim((string)($row["driver_name"] ?? ""));
    $driverId = trim((string)($row["driver_id"] ?? ""));

    $relatorioMesas[$mesa]["rotas_total"]++;
    $relatorioMesas[$mesa]["segundos_total"] += max(0, $duracao);

    if ($rotaTexto !== "") {
        $relatorioMesas[$mesa]["rotas_unicas"][$rotaTexto] = true;
    }

    if ($driverName !== "") {
        $nomeMotorista = $driverName;
        if ($driverId !== "") {
            $nomeMotorista .= " (ID: {$driverId})";
        }
        $relatorioMesas[$mesa]["motoristas_unicos"][$nomeMotorista] = true;
    }

    $relatorioMesas[$mesa]["registros"][] = [
        "rota_texto" => $rotaTexto,
        "driver_name" => $driverName,
        "driver_id" => $driverId,
        "duration_seconds" => $duracao
    ];

    $totalRotasMesas++;
    $totalSegundosMesas += max(0, $duracao);
}

ksort($relatorioMesas);

$tempoMedioGeral = $totalRotasMesas > 0 ? (int)floor($totalSegundosMesas / $totalRotasMesas) : 0;
$rotasPorHoraGeral = $totalSegundosMesas > 0 ? round($totalRotasMesas / ($totalSegundosMesas / 3600), 2) : 0;

if (isset($_GET["export"]) && $_GET["export"] === "mesas_csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=relatorio_mesas.csv");

    $out = fopen("php://output", "w");
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, [
        "Mesa",
        "Qtd Rotas",
        "Tempo Total",
        "Tempo Medio por Rota",
        "Rotas por Hora",
        "1 Rota a Cada",
        "Rotas Feitas",
        "Motoristas"
    ], ";");

    foreach ($relatorioMesas as $mesa => $dados) {
        $rotas = array_keys($dados["rotas_unicas"]);
        sort($rotas);

        $motoristas = array_keys($dados["motoristas_unicos"]);
        sort($motoristas);

        $tempoMedioMesa = $dados["rotas_total"] > 0 ? (int)floor($dados["segundos_total"] / $dados["rotas_total"]) : 0;
        $rotasPorHoraMesa = $dados["segundos_total"] > 0 ? round($dados["rotas_total"] / ($dados["segundos_total"] / 3600), 2) : 0;

        fputcsv($out, [
            "Mesa " . $dados["mesa_numero"],
            $dados["rotas_total"],
            formatarDuracao($dados["segundos_total"]),
            formatarDuracao($tempoMedioMesa),
            $rotasPorHoraMesa,
            formatarMinutosPorRota($tempoMedioMesa),
            implode(" | ", $rotas),
            implode(" | ", $motoristas)
        ], ";");
    }

    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel Admin</title>
<style>
:root{
    --bg:#f5f7fb;
    --bg-soft:#eef3f9;
    --card:#ffffff;
    --line:#e8edf5;
    --line-strong:#d8e0eb;
    --text:#162033;
    --muted:#667085;
    --brand:#ee4d2d;
    --brand-2:#ff6a3d;
    --brand-dark:#cf3f1f;
    --blue:#2563eb;
    --blue-2:#1d4ed8;
    --gray:#667085;
    --gray-2:#475467;
    --dark:#111827;
    --dark-2:#0b1220;
    --green:#16a34a;
    --green-bg:#ecfdf3;
    --amber:#f59e0b;
    --amber-bg:#fff7ed;
    --red:#dc2626;
    --red-2:#b91c1c;
    --shadow-sm:0 8px 18px rgba(15,23,42,.05);
    --shadow-md:0 18px 40px rgba(15,23,42,.08);
    --radius:20px;
    --radius-sm:14px;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
    margin:0;
    font-family:Arial,sans-serif;
    color:var(--text);
    background:radial-gradient(circle at top left,#ffffff 0%,var(--bg) 38%,var(--bg-soft) 100%);
}
.topo{
    position:sticky;
    top:0;
    z-index:20;
    background:linear-gradient(90deg,var(--brand) 0%,var(--brand-2) 100%);
    color:#fff;
    padding:18px 28px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    box-shadow:0 12px 34px rgba(238,77,45,.22);
}
.topo-esq{
    display:flex;
    flex-direction:column;
    gap:4px;
}
.topo h2{
    margin:0;
    font-size:29px;
    font-weight:900;
    letter-spacing:.2px;
}
.topo-sub{
    font-size:13px;
    opacity:.92;
}
.acoes-topo{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.container{
    max-width:1500px;
    margin:0 auto;
    padding:28px;
}
.hero{
    display:grid;
    grid-template-columns:1.35fr .95fr;
    gap:18px;
    margin-bottom:22px;
}
.hero-card{
    border-radius:26px;
    padding:26px;
    color:#fff;
    position:relative;
    overflow:hidden;
    box-shadow:var(--shadow-md);
}
.hero-card::after{
    content:"";
    position:absolute;
    inset:auto -80px -80px auto;
    width:220px;
    height:220px;
    background:rgba(255,255,255,.10);
    border-radius:50%;
}
.hero-main{
    background:linear-gradient(135deg,#ff6a3d 0%, #ee4d2d 45%, #d83b1b 100%);
}
.hero-side{
    background:linear-gradient(135deg,#18233d 0%, #0f172a 100%);
}
.hero-title{
    margin:0 0 8px 0;
    font-size:30px;
    font-weight:900;
}
.hero-text{
    margin:0;
    max-width:760px;
    line-height:1.55;
    opacity:.95;
}
.hero-mini-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(120px,1fr));
    gap:14px;
    margin-top:18px;
}
.hero-mini{
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.14);
    border-radius:18px;
    padding:14px;
    backdrop-filter:blur(4px);
}
.hero-mini-label{
    font-size:12px;
    opacity:.9;
    margin-bottom:6px;
}
.hero-mini-value{
    font-size:26px;
    font-weight:900;
}

.card{
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    border:1px solid var(--line);
    padding:22px;
    border-radius:var(--radius);
    margin-bottom:22px;
    box-shadow:var(--shadow-sm);
}
.card:hover{
    box-shadow:var(--shadow-md);
}
.card h3{
    margin:0 0 18px 0;
    font-size:22px;
    font-weight:900;
}
.bloco-info{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    margin-bottom:16px;
    flex-wrap:wrap;
}
.bloco-info p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    max-width:560px;
    line-height:1.45;
}
.btn{
    appearance:none;
    border:none;
    outline:none;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 16px;
    border-radius:14px;
    cursor:pointer;
    font-size:14px;
    font-weight:800;
    color:#fff;
    transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    box-shadow:0 8px 18px rgba(0,0,0,.10);
}
.btn:hover{
    transform:translateY(-1px);
    opacity:.96;
}
.btn-brand{
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-2) 100%);
}
.btn-sec{
    background:linear-gradient(135deg,var(--gray) 0%,var(--gray-2) 100%);
}
.btn-edit{
    background:linear-gradient(135deg,var(--blue) 0%,var(--blue-2) 100%);
}
.btn-sair{
    background:linear-gradient(135deg,var(--dark) 0%,var(--dark-2) 100%);
}
.btn-ok{
    background:linear-gradient(135deg,#16a34a 0%, #22c55e 100%);
}
.btn-danger{
    background:linear-gradient(135deg,var(--red) 0%, var(--red-2) 100%);
}
.kpis{
    display:grid;
    grid-template-columns:repeat(4,minmax(180px,1fr));
    gap:14px;
    margin-bottom:18px;
}
.kpi{
    border-radius:18px;
    padding:18px;
    border:1px solid var(--line);
    background:#fff;
    box-shadow:var(--shadow-sm);
}
.kpi-label{
    font-size:13px;
    color:var(--muted);
    margin-bottom:8px;
    font-weight:800;
}
.kpi-value{
    font-size:32px;
    font-weight:900;
    color:var(--text);
}
.form-area{
    display:grid;
    gap:14px;
}
.form-linha{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:#fff;
}
.import-grid{
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:14px;
    width:100%;
    align-items:end;
}
.input-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.input-group label{
    font-size:14px;
    font-weight:900;
    color:var(--text);
}
.input-group input,
.input-group select,
.input-group textarea{
    width:100%;
    padding:13px 14px;
    border:1px solid var(--line-strong);
    border-radius:14px;
    font-size:14px;
    color:var(--text);
    background:#fff;
    transition:border-color .15s ease, box-shadow .15s ease;
}
.input-group textarea{
    min-height:96px;
    resize:vertical;
}
.input-group input:focus,
.input-group select:focus,
.input-group textarea:focus{
    outline:none;
    border-color:#ffb29f;
    box-shadow:0 0 0 4px rgba(238,77,45,.10);
}
.criacao-grid{
    display:grid;
    grid-template-columns:repeat(12, minmax(0, 1fr));
    gap:14px;
}
.span-3{grid-column:span 3}
.span-4{grid-column:span 4}
.span-5{grid-column:span 5}
.span-6{grid-column:span 6}
.span-12{grid-column:span 12}
.form-box{
    background:linear-gradient(180deg,#ffffff 0%,#fcfdff 100%);
    border:1px solid var(--line);
    border-radius:20px;
    padding:18px;
}
.form-box h4{
    margin:0 0 8px 0;
    font-size:18px;
    font-weight:900;
}
.form-box p{
    margin:0 0 14px 0;
    color:var(--muted);
    font-size:13px;
    line-height:1.45;
}
.chips-ajuda{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:8px;
}
.chip-ajuda{
    display:inline-flex;
    align-items:center;
    padding:8px 11px;
    border-radius:999px;
    border:1px solid #e7eaf1;
    background:#f8fafc;
    color:#344054;
    font-size:12px;
    font-weight:800;
}
.preview-box{
    background:#fff7ed;
    border:1px solid #fed7aa;
    border-radius:16px;
    padding:12px 14px;
    color:#9a3412;
    font-size:13px;
    line-height:1.45;
}
.filtros{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.filtro-btn{
    text-decoration:none;
    padding:10px 14px;
    border-radius:999px;
    border:1px solid var(--line);
    background:#fff;
    color:var(--text);
    font-size:13px;
    font-weight:900;
}
.filtro-ativo{
    background:linear-gradient(135deg,var(--brand) 0%,var(--brand-2) 100%);
    color:#fff;
    border-color:transparent;
}
.table-wrap{
    overflow:auto;
    border:1px solid var(--line);
    border-radius:18px;
    background:#fff;
}
table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    min-width:1100px;
}
thead th{
    background:linear-gradient(180deg,#f9fbfd 0%,#f1f5f9 100%);
    color:#1f2937;
    font-weight:900;
    font-size:14px;
    text-align:left;
    padding:15px 12px;
    border-bottom:1px solid var(--line);
    white-space:nowrap;
}
tbody td{
    padding:14px 12px;
    border-bottom:1px solid #eef2f7;
    font-size:14px;
    vertical-align:top;
}
tbody tr:hover{
    background:#fcfdff;
}
.status{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    text-transform:capitalize;
}
.status::before{
    content:"";
    width:8px;
    height:8px;
    border-radius:50%;
    display:inline-block;
}
.status-ativo{
    color:#166534;
    background:#ecfdf3;
}
.status-ativo::before{
    background:#22c55e;
}
.status-conferindo{
    color:#92400e;
    background:#fff7ed;
}
.status-conferindo::before{
    background:#f59e0b;
}
.status-finalizado{
    color:#475467;
    background:#f2f4f7;
}
.status-finalizado::before{
    background:#98a2b3;
}
.tag{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    background:#f3f6fb;
    color:#344054;
    border:1px solid #e6ebf2;
}
.vazio{
    text-align:center;
    color:var(--muted);
    padding:24px;
    font-size:15px;
}
.acoes-tabela{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.btn-mini{
    appearance:none;
    border:none;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 12px;
    border-radius:12px;
    font-size:13px;
    font-weight:800;
    color:#fff;
    box-shadow:0 8px 18px rgba(0,0,0,.10);
}
.btn-mini.edit{
    background:linear-gradient(135deg,var(--blue) 0%,var(--blue-2) 100%);
}
.btn-mini.delete{
    background:linear-gradient(135deg,var(--red) 0%,var(--red-2) 100%);
}
.modal-feedback{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.40);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
    padding:18px;
}
.modal-feedback.ativo{
    display:flex;
}
.modal-card{
    width:100%;
    max-width:480px;
    background:#fff;
    border-radius:24px;
    box-shadow:0 24px 60px rgba(15,23,42,.20);
    padding:24px;
    text-align:center;
    animation:modalIn .18s ease;
}
.modal-icon{
    width:70px;
    height:70px;
    border-radius:50%;
    margin:0 auto 14px auto;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    font-weight:900;
}
.modal-icon.success{
    background:#ecfdf3;
    color:#16a34a;
}
.modal-icon.error{
    background:#fff1f2;
    color:#dc2626;
}
.modal-title{
    font-size:24px;
    font-weight:900;
    margin:0 0 10px 0;
}
.modal-text{
    font-size:15px;
    color:var(--muted);
    line-height:1.5;
    margin:0 0 18px 0;
    white-space:pre-line;
}
.modal-actions{
    display:flex;
    gap:10px;
    justify-content:center;
    flex-wrap:wrap;
}
@keyframes modalIn{
    from{opacity:0;transform:translateY(8px) scale(.98)}
    to{opacity:1;transform:translateY(0) scale(1)}
}
@media (max-width:1100px){
    .hero{
        grid-template-columns:1fr;
    }
    .kpis{
        grid-template-columns:repeat(2,minmax(140px,1fr));
    }
    .import-grid{
        grid-template-columns:1fr;
    }
    .criacao-grid{
        grid-template-columns:1fr;
    }
    .span-3,.span-4,.span-5,.span-6,.span-12{
        grid-column:span 1;
    }
}
@media (max-width:720px){
    .container{
        padding:16px;
    }
    .topo{
        padding:18px 16px;
        flex-direction:column;
        align-items:flex-start;
    }
    .topo h2{
        font-size:24px;
    }
    .hero-title{
        font-size:24px;
    }
    .kpis{
        grid-template-columns:1fr;
    }
    .btn{
        width:100%;
    }
}
</style>
</head>
<body>

<div class="topo">
    <div class="topo-esq">
        <h2>Painel Administrador</h2>
        <div class="topo-sub">Gerencie rotas, motoristas, status e produtividade das mesas</div>
    </div>
    <div class="acoes-topo">
        <a href="produtividade.php" class="btn btn-brand">Dashboard Produtividade</a>
        <a href="admin.php?export=mesas_csv" class="btn btn-sec">Baixar Relatório CSV</a>
        <a href="conferente.php" class="btn btn-sec">Painel Conferente</a>
        <a href="logout.php" class="btn btn-sair">Sair</a>
    </div>
</div>

<div class="container">

    <div class="hero">
        <div class="hero-card hero-main">
            <h1 class="hero-title">Central de rotas e produtividade</h1>
            <p class="hero-text">
                Aqui você importa a escala, cria rotas manualmente, acompanha o status dos motoristas e gerencia a operação em um único painel.
            </p>

            <div class="hero-mini-grid">
                <div class="hero-mini">
                    <div class="hero-mini-label">Motoristas ativos</div>
                    <div class="hero-mini-value"><?= $totalAtivos ?></div>
                </div>
                <div class="hero-mini">
                    <div class="hero-mini-label">Em conferência</div>
                    <div class="hero-mini-value"><?= $totalConferindo ?></div>
                </div>
                <div class="hero-mini">
                    <div class="hero-mini-label">Finalizados</div>
                    <div class="hero-mini-value"><?= $totalFinalizados ?></div>
                </div>
                <div class="hero-mini">
                    <div class="hero-mini-label">Rotas registradas</div>
                    <div class="hero-mini-value"><?= $totalRotasMesas ?></div>
                </div>
            </div>
        </div>

        <div class="hero-card hero-side">
            <h2 class="hero-title" style="font-size:24px;">Leitura rápida da operação</h2>
            <p class="hero-text">
                Visual limpo para identificar rapidamente quantidade de motoristas, andamento da conferência, criação manual de rotas e exportação de relatório.
            </p>

            <div class="hero-mini-grid">
                <div class="hero-mini">
                    <div class="hero-mini-label">Tempo médio geral</div>
                    <div class="hero-mini-value" style="font-size:22px;"><?= formatarDuracao($tempoMedioGeral) ?></div>
                </div>
                <div class="hero-mini">
                    <div class="hero-mini-label">Rotas por hora</div>
                    <div class="hero-mini-value" style="font-size:22px;"><?= $rotasPorHoraGeral ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="bloco-info">
            <h3>Resumo da Operação</h3>
            <p>Visão geral dos motoristas ativos na escala atual.</p>
        </div>

        <div class="kpis">
            <div class="kpi">
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?= $totalGeral ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Ativos</div>
                <div class="kpi-value"><?= $totalAtivos ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Conferindo</div>
                <div class="kpi-value"><?= $totalConferindo ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Finalizados</div>
                <div class="kpi-value"><?= $totalFinalizados ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="bloco-info">
            <h3>Importar Rotas</h3>
            <p>Selecione os dois arquivos e envie tudo de uma vez.</p>
        </div>

        <div class="form-area">
            <form action="importar_escala.php" method="post" enctype="multipart/form-data" class="form-linha">
                <div class="import-grid">
                    <div class="input-group">
                        <label for="arquivo_normal">Arquivo Normal</label>
                        <input type="file" name="arquivo_normal" id="arquivo_normal" accept=".csv" required>
                    </div>

                    <div class="input-group">
                        <label for="arquivo_moto">Arquivo Moto</label>
                        <input type="file" name="arquivo_moto" id="arquivo_moto" accept=".csv" required>
                    </div>

                    <button class="btn btn-brand" type="submit">Importar Escala Completa</button>
                </div>
            </form>

            <form action="limpar_escala.php" method="post" class="form-linha" onsubmit="return abrirConfirmacaoAcao(this, 'Tem certeza que deseja limpar a escala atual?');">
                <button class="btn btn-sec" type="submit">Limpar Escala</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="bloco-info">
            <h3>Criar rota manual</h3>
            <p>
                Crie ou atualize um motorista diretamente pelo painel. Para Fiorino e Passeio use o padrão normal. Para MOTO use rota exibida + fórmula moto.
            </p>
        </div>

        <form method="post" class="form-area">
            <input type="hidden" name="action" value="create_route">

            <div class="criacao-grid">
                <div class="form-box span-12">
                    <h4>Dados do motorista</h4>
                    <p>Esses dados serão usados para inserir ou atualizar o motorista na base ativa.</p>

                    <div class="criacao-grid">
                        <div class="input-group span-3">
                            <label for="driver_id_manual">ID do motorista</label>
                            <input type="text" name="driver_id_manual" id="driver_id_manual" placeholder="Ex: 1955336" required>
                        </div>

                        <div class="input-group span-5">
                            <label for="driver_name_manual">Nome do motorista</label>
                            <input type="text" name="driver_name_manual" id="driver_name_manual" placeholder="Ex: ADILSON TEIXEIRA LOPES" required>
                        </div>

                        <div class="input-group span-4">
                            <label for="vehicle_type_manual">Tipo de veículo</label>
                            <select name="vehicle_type_manual" id="vehicle_type_manual" required>
                                <option value="">Selecione</option>
                                <option value="PASSEIO">PASSEIO</option>
                                <option value="FIORINO">FIORINO</option>
                                <option value="ZOF-PASSEIO-AR">ZOF-PASSEIO-AR</option>
                                <option value="ZOF-FIORINO-AR">ZOF-FIORINO-AR</option>
                                <option value="MOTO">MOTO</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-box span-6" id="boxNormal">
                    <h4>Criação padrão normal</h4>
                    <p>Use esse formato para carro, passeio e fiorino.</p>

                    <div class="input-group">
                        <label for="cluster_normal_manual">Rota no padrão normal</label>
                        <input type="text" name="cluster_normal_manual" id="cluster_normal_manual" placeholder="Ex: A-1+B-2/120">
                    </div>

                    <div class="chips-ajuda">
                        <span class="chip-ajuda">A-1+B-2/120</span>
                        <span class="chip-ajuda">B-14+B-15/111</span>
                        <span class="chip-ajuda">C-21/90</span>
                    </div>

                    <div class="preview-box" style="margin-top:12px;">
                        Para veículos normais, o sistema lê o total após a barra e divide os pacotes entre os clusters para salvar em <strong>driver_clusters</strong>.
                    </div>
                </div>

                <div class="form-box span-6" id="boxMoto">
                    <h4>Criação padrão moto</h4>
                    <p>Use rota exibida + fórmula. A soma dos pacotes sai da fórmula.</p>

                    <div class="input-group">
                        <label for="cluster_moto_manual">Rota exibida da moto</label>
                        <input type="text" name="cluster_moto_manual" id="cluster_moto_manual" placeholder="Ex: D-3+D-4">
                    </div>

                    <div class="input-group" style="margin-top:12px;">
                        <label for="moto_formula_manual">Fórmula moto</label>
                        <textarea name="moto_formula_manual" id="moto_formula_manual" placeholder="Ex: D-3(60)+D-4(55)"></textarea>
                    </div>

                    <div class="chips-ajuda">
                        <span class="chip-ajuda">D-3(60)+D-4(55)</span>
                        <span class="chip-ajuda">C-21(23)+C-20(46)+C-18(54)</span>
                    </div>

                    <div class="preview-box" id="previewMoto" style="margin-top:12px;">
                        Total calculado pela fórmula: <strong>0</strong> pacotes.
                    </div>
                </div>

                <div class="span-12" style="display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-ok">Salvar rota manual</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="bloco-info">
            <h3>Motoristas</h3>
            <p>Filtre rapidamente os registros por status.</p>
        </div>

        <div class="filtros">
            <a href="admin.php?status=todos" class="filtro-btn <?= filtroAtivo($filtroStatus, 'todos') ?>">Todos</a>
            <a href="admin.php?status=ativo" class="filtro-btn <?= filtroAtivo($filtroStatus, 'ativo') ?>">Ativos</a>
            <a href="admin.php?status=conferindo" class="filtro-btn <?= filtroAtivo($filtroStatus, 'conferindo') ?>">Conferindo</a>
            <a href="admin.php?status=finalizado" class="filtro-btn <?= filtroAtivo($filtroStatus, 'finalizado') ?>">Finalizados</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Cluster</th>
                        <th>Pacotes</th>
                        <th>Veículo</th>
                        <th>Status</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($drivers) > 0): ?>
                        <?php foreach($drivers as $d): ?>
                            <?php
                                $status = strtolower(trim((string)$d["status"]));
                                $statusClass = "status-finalizado";
                                if ($status === "ativo") $statusClass = "status-ativo";
                                elseif ($status === "conferindo") $statusClass = "status-conferindo";
                            ?>
                            <tr>
                                <td><span class="tag"><?= htmlspecialchars($d["driver_id"]) ?></span></td>
                                <td><strong><?= htmlspecialchars($d["driver_name"]) ?></strong></td>
                                <td><?= htmlspecialchars($d["cluster_text"]) ?></td>
                                <td><strong><?= htmlspecialchars($d["packages_total"]) ?></strong></td>
                                <td><?= htmlspecialchars($d["vehicle_type"]) ?></td>
                                <td><span class="status <?= $statusClass ?>"><?= htmlspecialchars($d["status"]) ?></span></td>
                                <td>
                                    <div class="acoes-tabela">
                                        <a class="btn-mini edit" href="editar_motorista.php?id=<?= $d["id"] ?>">Editar</a>

                                        <form method="post" class="form-delete-route" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_route">
                                            <input type="hidden" name="delete_driver_id" value="<?= (int)$d["id"] ?>">
                                            <button type="submit" class="btn-mini delete">
                                                Excluir
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="vazio">Nenhum motorista encontrado para esse filtro.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div class="modal-feedback" id="modalFeedback">
    <div class="modal-card">
        <div class="modal-icon" id="modalIcon">!</div>
        <h3 class="modal-title" id="modalTitle">Aviso</h3>
        <p class="modal-text" id="modalText"></p>
        <div class="modal-actions">
            <button class="btn btn-brand" id="modalCloseBtn" type="button">OK</button>
        </div>
    </div>
</div>

<div class="modal-feedback" id="modalConfirmacao">
    <div class="modal-card">
        <div class="modal-icon error">!</div>
        <h3 class="modal-title">Confirmação</h3>
        <p class="modal-text" id="modalConfirmText">Deseja continuar?</p>
        <div class="modal-actions">
            <button class="btn btn-sec" id="confirmCancelBtn" type="button">Cancelar</button>
            <button class="btn btn-danger" id="confirmOkBtn" type="button">Confirmar</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script>
const ADMIN_SUPABASE_URL = "https://uyqnkvegjqsnejlrgetc.supabase.co";
const ADMIN_SUPABASE_ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmVnanFzbmVqbHJnZXRjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM2ODA3NjgsImV4cCI6MjA4OTI1Njc2OH0.f7ytVVrtdiNK4ROQ-Epxt9o0Pda1YiNF2V2sXhRjaE8";
const adminSupabase = window.supabase.createClient(ADMIN_SUPABASE_URL, ADMIN_SUPABASE_ANON_KEY);

let adminReloadTimer = null;

function recarregarAdminRealtime() {
    if (adminReloadTimer) return;
    adminReloadTimer = setTimeout(() => {
        window.location.reload();
    }, 400);
}

adminSupabase
    .channel("admin-realtime-geral")
    .on("postgres_changes", { event: "*", schema: "public", table: "drivers" }, () => recarregarAdminRealtime())
    .on("postgres_changes", { event: "*", schema: "public", table: "mesa_tempos" }, () => recarregarAdminRealtime())
    .on("postgres_changes", { event: "*", schema: "public", table: "mesa_controle" }, () => recarregarAdminRealtime())
    .subscribe((status) => {
        console.log("Canal realtime admin:", status);
    });

const vehicleTypeManual = document.getElementById("vehicle_type_manual");
const boxNormal = document.getElementById("boxNormal");
const boxMoto = document.getElementById("boxMoto");
const motoFormulaManual = document.getElementById("moto_formula_manual");
const previewMoto = document.getElementById("previewMoto");

function atualizarBlocosCriacao() {
    const valor = (vehicleTypeManual.value || "").toUpperCase();

    if (valor === "MOTO") {
        boxMoto.style.opacity = "1";
        boxMoto.style.borderColor = "#fed7aa";
        boxNormal.style.opacity = ".65";
        boxNormal.style.borderColor = "";
    } else if (valor) {
        boxNormal.style.opacity = "1";
        boxNormal.style.borderColor = "#fed7aa";
        boxMoto.style.opacity = ".65";
        boxMoto.style.borderColor = "";
    } else {
        boxNormal.style.opacity = "1";
        boxMoto.style.opacity = "1";
        boxNormal.style.borderColor = "";
        boxMoto.style.borderColor = "";
    }
}

function somarFormulaMoto(texto) {
    const regex = /([A-Z]-\d+)\((\d+)\)/gi;
    let match;
    let total = 0;

    while ((match = regex.exec(texto)) !== null) {
        total += parseInt(match[2], 10) || 0;
    }

    return total;
}

function atualizarPreviewMoto() {
    const total = somarFormulaMoto(motoFormulaManual.value || "");
    previewMoto.innerHTML = `Total calculado pela fórmula: <strong>${total}</strong> pacotes.`;
}

vehicleTypeManual.addEventListener("change", atualizarBlocosCriacao);
motoFormulaManual.addEventListener("input", atualizarPreviewMoto);

atualizarBlocosCriacao();
atualizarPreviewMoto();

const modalFeedback = document.getElementById("modalFeedback");
const modalIcon = document.getElementById("modalIcon");
const modalTitle = document.getElementById("modalTitle");
const modalText = document.getElementById("modalText");
const modalCloseBtn = document.getElementById("modalCloseBtn");

function abrirModalFeedback(tipo, titulo, texto) {
    modalIcon.className = "modal-icon " + tipo;
    modalIcon.textContent = tipo === "success" ? "✓" : "!";
    modalTitle.textContent = titulo;
    modalText.textContent = texto;
    modalFeedback.classList.add("ativo");
}

function fecharModalFeedback() {
    modalFeedback.classList.remove("ativo");
}

modalCloseBtn.addEventListener("click", fecharModalFeedback);

<?php if ($flashSuccess): ?>
abrirModalFeedback("success", "Sucesso", <?= json_encode($flashSuccess) ?>);
<?php endif; ?>

<?php if ($flashError): ?>
abrirModalFeedback("error", "Erro", <?= json_encode($flashError) ?>);
<?php endif; ?>

const modalConfirmacao = document.getElementById("modalConfirmacao");
const modalConfirmText = document.getElementById("modalConfirmText");
const confirmCancelBtn = document.getElementById("confirmCancelBtn");
const confirmOkBtn = document.getElementById("confirmOkBtn");

let formPendente = null;

function abrirConfirmacaoAcao(form, mensagem) {
    formPendente = form;
    modalConfirmText.textContent = mensagem;
    modalConfirmacao.classList.add("ativo");
    return false;
}

function fecharConfirmacao() {
    modalConfirmacao.classList.remove("ativo");
    formPendente = null;
}

confirmCancelBtn.addEventListener("click", fecharConfirmacao);

confirmOkBtn.addEventListener("click", function() {
    if (formPendente) {
        const form = formPendente;
        fecharConfirmacao();
        form.submit();
    }
});

document.querySelectorAll(".form-delete-route").forEach(form => {
    form.addEventListener("submit", function(e) {
        e.preventDefault();
        abrirConfirmacaoAcao(this, "Tem certeza que deseja excluir esta rota/motorista? Esta ação também remove clusters, mesa_controle e tempos relacionados.");
    });
});
</script>

</body>
</html>