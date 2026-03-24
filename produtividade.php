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

$tempoMedioGeral = $totalRotasMesas > 0 ? (int)floor($totalSegundosMesas / $totalRotasMesas) : 0;
$rotasPorHoraGeral = $totalSegundosMesas > 0 ? round($totalRotasMesas / ($totalSegundosMesas / 3600), 2) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard de Produtividade</title>
<style>
:root{
    --bg:#f5f7fb;
    --bg-soft:#eef3f9;
    --card:#ffffff;
    --line:#e8edf5;
    --text:#162033;
    --muted:#667085;
    --brand:#ee4d2d;
    --brand-2:#ff6a3d;
    --dark:#111827;
    --dark-2:#0b1220;
    --gray:#667085;
    --gray-2:#475467;
    --shadow-sm:0 8px 18px rgba(15,23,42,.05);
    --shadow-md:0 18px 40px rgba(15,23,42,.08);
    --radius:20px;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at top left,#ffffff 0%,var(--bg) 38%,var(--bg-soft) 100%);
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
    font-size:28px;
    font-weight:900;
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
    transition:transform .15s ease, opacity .15s ease;
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
.container{
    max-width:1500px;
    margin:0 auto;
    padding:28px;
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
.bloco-info{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    margin-bottom:16px;
    flex-wrap:wrap;
}
.card h3{
    margin:0;
    font-size:22px;
    font-weight:900;
}
.bloco-info p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    line-height:1.45;
}
.kpis{
    display:grid;
    grid-template-columns:repeat(4,minmax(180px,1fr));
    gap:14px;
}
.kpi{
    border-radius:18px;
    padding:18px;
    border:1px solid #fed7aa;
    background:linear-gradient(180deg,#ffffff 0%,#fff7ed 100%);
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
    min-width:1200px;
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
.vazio{
    text-align:center;
    color:var(--muted);
    padding:24px;
    font-size:15px;
}
@media (max-width:1100px){
    .kpis{
        grid-template-columns:repeat(2,minmax(140px,1fr));
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
        <h2>Dashboard de Produtividade</h2>
        <div class="topo-sub">Visual separado para acompanhar o desempenho das mesas</div>
    </div>
    <div class="acoes-topo">
        <a href="admin.php" class="btn btn-sec">Voltar ao Admin</a>
        <a href="admin.php?export=mesas_csv" class="btn btn-brand">Baixar Relatório CSV</a>
    </div>
</div>

<div class="container">
    <div class="card">
        <div class="bloco-info">
            <h3>Relatório Completo das Mesas</h3>
            <p>Mostra produtividade real de cada mesa com rotas, motoristas e médias.</p>
        </div>

        <div class="kpis">
            <div class="kpi">
                <div class="kpi-label">Total de Rotas Registradas</div>
                <div class="kpi-value"><?= $totalRotasMesas ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Tempo Médio Geral</div>
                <div class="kpi-value"><?= formatarDuracao($tempoMedioGeral) ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Rotas por Hora (Geral)</div>
                <div class="kpi-value"><?= $rotasPorHoraGeral ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">1 Rota a Cada</div>
                <div class="kpi-value"><?= formatarMinutosPorRota($tempoMedioGeral) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
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
                        <tr><td colspan="9" class="vazio">Nenhum registro finalizado encontrado para as mesas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script>
const PROD_SUPABASE_URL = "https://uyqnkvegjqsnejlrgetc.supabase.co";
const PROD_SUPABASE_ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InV5cW5rdmVnanFzbmVqbHJnZXRjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM2ODA3NjgsImV4cCI6MjA4OTI1Njc2OH0.f7ytVVrtdiNK4ROQ-Epxt9o0Pda1YiNF2V2sXhRjaE8";
const prodSupabase = window.supabase.createClient(PROD_SUPABASE_URL, PROD_SUPABASE_ANON_KEY);

let reloadTimer = null;

function recarregarProdutividade() {
    if (reloadTimer) return;
    reloadTimer = setTimeout(() => {
        window.location.reload();
    }, 400);
}

prodSupabase
    .channel("produtividade-realtime")
    .on("postgres_changes", { event: "*", schema: "public", table: "mesa_tempos" }, () => recarregarProdutividade())
    .subscribe((status) => {
        console.log("Canal produtividade:", status);
    });
</script>

</body>
</html>