<?php
require "db.php";

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

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
   RELATÓRIO COMPLETO MESAS
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
    $vehicleType = trim((string)($row["vehicle_type"] ?? ""));
    $startedAt = trim((string)($row["started_at"] ?? ""));
    $finishedAt = trim((string)($row["finished_at"] ?? ""));

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
        "vehicle_type" => $vehicleType,
        "duration_seconds" => $duracao,
        "started_at" => $startedAt,
        "finished_at" => $finishedAt
    ];

    $totalRotasMesas++;
    $totalSegundosMesas += max(0, $duracao);
}

ksort($relatorioMesas);

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

$tempoMedioGeral = $totalRotasMesas > 0 ? (int)floor($totalSegundosMesas / $totalRotasMesas) : 0;
$rotasPorHoraGeral = $totalSegundosMesas > 0 ? round($totalRotasMesas / ($totalSegundosMesas / 3600), 2) : 0;

/* =========================
   EXPORTAR CSV
========================= */
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
    --bg:#f4f7fb;
    --bg-soft:#eef3f9;
    --card:#ffffff;
    --line:#e7edf5;
    --text:#172033;
    --muted:#667085;

    --brand:#ee4d2d;
    --brand-2:#ff6a3d;
    --brand-dark:#cc3f23;

    --blue:#2563eb;
    --blue-2:#1d4ed8;

    --gray:#6b7280;
    --gray-2:#4b5563;

    --dark:#111827;
    --dark-2:#0b1220;

    --green:#22c55e;
    --green-soft:#ecfdf3;

    --amber:#f59e0b;
    --amber-soft:#fff7ed;

    --slate:#98a2b3;
    --slate-soft:#f2f4f7;

    --shadow-sm:0 6px 16px rgba(15,23,42,.06);
    --shadow-md:0 12px 28px rgba(15,23,42,.10);
    --shadow-lg:0 18px 40px rgba(15,23,42,.14);

    --radius:18px;
}

*{ box-sizing:border-box; }

html{ scroll-behavior:smooth; }

body{
    margin:0;
    font-family:Arial, sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at top left, #ffffff 0%, var(--bg) 38%, var(--bg-soft) 100%);
}

.topo{
    position:sticky;
    top:0;
    z-index:10;
    background:linear-gradient(90deg, var(--brand) 0%, var(--brand-2) 100%);
    color:#fff;
    padding:20px 28px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    box-shadow:0 10px 30px rgba(238,77,45,.20);
}

.topo-esq{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.topo h2{
    margin:0;
    font-size:24px;
    font-weight:800;
}

.topo-sub{
    font-size:13px;
    opacity:.9;
}

.acoes-topo{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.container{
    max-width:1450px;
    margin:0 auto;
    padding:28px;
}

.card{
    background:linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
    border:1px solid var(--line);
    padding:22px;
    border-radius:var(--radius);
    margin-bottom:22px;
    box-shadow:var(--shadow-sm);
}

.card h3{
    margin:0 0 18px 0;
    font-size:20px;
    font-weight:800;
}

.bloco-info{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:16px;
    flex-wrap:wrap;
}

.bloco-info p{
    margin:0;
    color:var(--muted);
    font-size:14px;
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
    padding:11px 16px;
    border-radius:12px;
    cursor:pointer;
    font-size:14px;
    font-weight:700;
    color:#fff;
    transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    box-shadow:0 8px 18px rgba(0,0,0,.10);
}

.btn:hover{
    transform:translateY(-1px);
    opacity:.96;
}

.btn-brand{ background:linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%); }
.btn-sec{ background:linear-gradient(135deg, var(--gray) 0%, var(--gray-2) 100%); }
.btn-edit{ background:linear-gradient(135deg, var(--blue) 0%, var(--blue-2) 100%); }
.btn-sair{ background:linear-gradient(135deg, var(--dark) 0%, var(--dark-2) 100%); }

.form-area{ display:grid; gap:14px; }

.form-linha{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    padding:14px;
    border:1px solid var(--line);
    border-radius:14px;
    background:#fff;
}

.kpis{
    display:grid;
    grid-template-columns:repeat(4, minmax(180px, 1fr));
    gap:14px;
    margin-bottom:18px;
}

.kpis-4{
    display:grid;
    grid-template-columns:repeat(4, minmax(180px, 1fr));
    gap:14px;
    margin-bottom:18px;
}

.kpi{
    border-radius:16px;
    padding:16px 18px;
    border:1px solid var(--line);
    background:#fff;
    box-shadow:var(--shadow-sm);
}

.kpi-label{
    font-size:13px;
    color:var(--muted);
    margin-bottom:8px;
    font-weight:700;
}

.kpi-value{
    font-size:28px;
    font-weight:800;
    color:var(--text);
}

.kpi.relatorio{
    background:linear-gradient(180deg,#ffffff 0%,#fff7ed 100%);
    border-color:#fed7aa;
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
    font-weight:800;
}

.filtro-ativo{
    background:linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
    color:#fff;
    border-color:transparent;
}

.table-wrap{
    overflow:auto;
    border:1px solid var(--line);
    border-radius:16px;
    background:#fff;
}

table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    min-width:1100px;
}

thead th{
    background:linear-gradient(180deg, #f9fbfd 0%, #f1f5f9 100%);
    color:#1f2937;
    font-weight:800;
    font-size:14px;
    text-align:left;
    padding:14px 12px;
    border-bottom:1px solid var(--line);
    white-space:nowrap;
}

tbody td{
    padding:14px 12px;
    border-bottom:1px solid #eef2f7;
    font-size:14px;
    vertical-align:top;
}

.status{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
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
    font-weight:800;
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

.lista-detalhes{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.item-detalhe{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:10px 12px;
    line-height:1.45;
}

@media (max-width: 980px){
    .container{ padding:16px; }
    .topo{
        padding:18px 16px;
        flex-direction:column;
        align-items:flex-start;
    }
    .kpis, .kpis-4{
        grid-template-columns:repeat(2, minmax(140px, 1fr));
    }
}

@media (max-width: 640px){
    .kpis, .kpis-4{
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
        <a href="#relatorio-mesas" class="btn btn-brand">Relatório das Mesas</a>
        <a href="admin.php?export=mesas_csv" class="btn btn-sec">Baixar Relatório CSV</a>
        <a href="conferente.php" class="btn btn-sec">Painel Conferente</a>
        <a href="logout.php" class="btn btn-sair">Sair</a>
    </div>
</div>

<div class="container">

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
            <p>Use os arquivos corretos para atualizar a escala do dia.</p>
        </div>

        <div class="form-area">
            <form action="importar_normal.php" method="post" enctype="multipart/form-data" class="form-linha">
                <input type="file" name="arquivo" required>
                <button class="btn btn-brand" type="submit">Importar Normal</button>
            </form>

            <form action="importar_moto.php" method="post" enctype="multipart/form-data" class="form-linha">
                <input type="file" name="arquivo" required>
                <button class="btn btn-brand" type="submit">Importar Moto</button>
            </form>

            <form action="limpar_escala.php" method="post" onsubmit="return confirm('Tem certeza que deseja limpar a escala atual?');" class="form-linha">
                <button class="btn btn-sec" type="submit">Limpar Escala</button>
            </form>
        </div>
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
                                <td><a class="btn btn-edit" href="editar_motorista.php?id=<?= $d["id"] ?>">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="vazio">Nenhum motorista encontrado para esse filtro.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="relatorio-mesas">
        <div class="bloco-info">
            <h3>Relatório Completo das Mesas</h3>
            <p>Mostra produtividade real de cada mesa com rotas, motoristas e médias.</p>
        </div>

        <div class="kpis-4">
            <div class="kpi relatorio">
                <div class="kpi-label">Total de Rotas Registradas</div>
                <div class="kpi-value"><?= $totalRotasMesas ?></div>
            </div>
            <div class="kpi relatorio">
                <div class="kpi-label">Tempo Médio Geral</div>
                <div class="kpi-value"><?= formatarDuracao($tempoMedioGeral) ?></div>
            </div>
            <div class="kpi relatorio">
                <div class="kpi-label">Rotas por Hora (Geral)</div>
                <div class="kpi-value"><?= $rotasPorHoraGeral ?></div>
            </div>
            <div class="kpi relatorio">
                <div class="kpi-label">1 Rota a Cada</div>
                <div class="kpi-value"><?= formatarMinutosPorRota($tempoMedioGeral) ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mesa</th>
                        <th>Qtd Rotas</th>
                        <th>Rotas por Hora</th>
                        <th>Média por Rota</th>
                        <th>1 Rota a Cada</th>
                        <th>Tempo Total</th>
                        <th>Rotas Feitas</th>
                        <th>Motoristas</th>
                        <th>Detalhamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($relatorioMesas)): ?>
                        <?php foreach ($relatorioMesas as $mesa => $dados): ?>
                            <?php
                                $rotas = array_keys($dados["rotas_unicas"]);
                                sort($rotas);

                                $motoristas = array_keys($dados["motoristas_unicos"]);
                                sort($motoristas);

                                $tempoMedioMesa = $dados["rotas_total"] > 0 ? (int)floor($dados["segundos_total"] / $dados["rotas_total"]) : 0;
                                $rotasPorHoraMesa = $dados["segundos_total"] > 0 ? round($dados["rotas_total"] / ($dados["segundos_total"] / 3600), 2) : 0;
                            ?>
                            <tr>
                                <td><span class="tag">Mesa <?= (int)$dados["mesa_numero"] ?></span></td>
                                <td><strong><?= (int)$dados["rotas_total"] ?></strong></td>
                                <td><strong><?= $rotasPorHoraMesa ?></strong></td>
                                <td><strong><?= formatarDuracao($tempoMedioMesa) ?></strong></td>
                                <td><strong><?= formatarMinutosPorRota($tempoMedioMesa) ?></strong></td>
                                <td><?= formatarDuracao($dados["segundos_total"]) ?></td>
                                <td>
                                    <div class="lista-detalhes">
                                        <?php foreach ($rotas as $rota): ?>
                                            <div class="item-detalhe"><?= htmlspecialchars($rota) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="lista-detalhes">
                                        <?php foreach ($motoristas as $motorista): ?>
                                            <div class="item-detalhe"><?= htmlspecialchars($motorista) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="lista-detalhes">
                                        <?php foreach ($dados["registros"] as $registro): ?>
                                            <div class="item-detalhe">
                                                <strong>Rota:</strong> <?= htmlspecialchars($registro["rota_texto"]) ?><br>
                                                <strong>Motorista:</strong> <?= htmlspecialchars($registro["driver_name"]) ?><br>
                                                <strong>Tempo:</strong> <?= formatarDuracao($registro["duration_seconds"]) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="vazio">Nenhum registro finalizado encontrado para as mesas.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>