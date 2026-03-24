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

$flashSuccess = $_SESSION["flash_success"] ?? "";
$flashError = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "limpar_produtividade") {
    try {
        $pdo->beginTransaction();

        $pdo->exec("TRUNCATE TABLE mesa_tempos RESTART IDENTITY");

        $pdo->commit();

        $_SESSION["flash_success"] = "Dados de produtividade zerados com sucesso.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["flash_error"] = "Erro ao limpar dados de produtividade: " . $e->getMessage();
    }

    header("Location: produtividade.php");
    exit;
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
    --red:#dc2626;
    --red-2:#b91c1c;
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
.btn-danger{
    background:linear-gradient(135deg,var(--red) 0%,var(--red-2) 100%);
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
    min-width:1350px;
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
.flash{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:18px;
    font-weight:800;
    font-size:14px;
}
.flash.success{
    background:#ecfdf3;
    color:#166534;
    border:1px solid #bbf7d0;
}
.flash.error{
    background:#fff1f2;
    color:#b91c1c;
    border:1px solid #fecdd3;
}
.details-box{
    min-width:240px;
}
.details-box summary{
    list-style:none;
    cursor:pointer;
    user-select:none;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:12px 14px;
    border-radius:14px;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    font-weight:900;
    color:#1f2937;
}
.details-box summary::-webkit-details-marker{
    display:none;
}
.details-box[open] summary{
    background:#fff7ed;
    border-color:#fed7aa;
    color:#9a3412;
}
.details-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:28px;
    height:28px;
    padding:0 8px;
    border-radius:999px;
    background:#fff;
    border:1px solid #e5e7eb;
    font-size:12px;
    font-weight:900;
}
.details-content{
    margin-top:10px;
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

        <form method="post" id="formLimparProdutividade" style="margin:0;">
            <input type="hidden" name="action" value="limpar_produtividade">
            <button type="submit" class="btn btn-danger">Limpar Dados</button>
        </form>
    </div>
</div>

<div class="container">

    <?php if ($flashSuccess): ?>
        <div class="flash success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="flash error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

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
                                <td><strong><?= formatarDuracao($dados["segundos_total"]) ?></strong></td>

                                <td>
                                    <details class="details-box">
                                        <summary>
                                            <span>Ver rotas</span>
                                            <span class="details-count"><?= count($rotas) ?></span>
                                        </summary>
                                        <div class="details-content">
                                            <div class="lista-detalhes">
                                                <?php foreach ($rotas as $rota): ?>
                                                    <div class="item-detalhe"><?= htmlspecialchars($rota) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
                                </td>

                                <td>
                                    <details class="details-box">
                                        <summary>
                                            <span>Ver motoristas</span>
                                            <span class="details-count"><?= count($motoristas) ?></span>
                                        </summary>
                                        <div class="details-content">
                                            <div class="lista-detalhes">
                                                <?php foreach ($motoristas as $motorista): ?>
                                                    <div class="item-detalhe"><?= htmlspecialchars($motorista) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
                                </td>

                                <td>
                                    <details class="details-box">
                                        <summary>
                                            <span>Ver detalhamento</span>
                                            <span class="details-count"><?= count($dados["registros"]) ?></span>
                                        </summary>
                                        <div class="details-content">
                                            <div class="lista-detalhes">
                                                <?php foreach ($dados["registros"] as $registro): ?>
                                                    <div class="item-detalhe">
                                                        <strong>Rota:</strong> <?= htmlspecialchars($registro["rota_texto"]) ?><br>
                                                        <strong>Motorista:</strong> <?= htmlspecialchars($registro["driver_name"]) ?><br>
                                                        <strong>Veículo:</strong> <?= htmlspecialchars($registro["vehicle_type"]) ?><br>
                                                        <strong>Tempo:</strong> <?= formatarDuracao($registro["duration_seconds"]) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
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
const PROD_SUPABASE_URL = "https://uyqnkvegjqsnejlrgetc.supabase.co";
const PROD_SUPABASE_ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJIUzI1NiIsInR5cCI6IkpXVCJ9";
</script>

<script>
const prodSupabase = window.supabase.createClient(
    "https://uyqnkvegjqsnejlrgetc.supabase.co",
    "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InV5cW5rdmVnanFzbmVqbHJnZXRjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM2ODA3NjgsImV4cCI6MjA4OTI1Njc2OH0.f7ytVVrtdiNK4ROQ-Epxt9o0Pda1YiNF2V2sXhRjaE8"
);

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
const formLimparProdutividade = document.getElementById("formLimparProdutividade");

let acaoPendente = null;

function abrirConfirmacao(mensagem, callback) {
    acaoPendente = callback;
    modalConfirmText.textContent = mensagem;
    modalConfirmacao.classList.add("ativo");
}

function fecharConfirmacao() {
    modalConfirmacao.classList.remove("ativo");
    acaoPendente = null;
}

confirmCancelBtn.addEventListener("click", fecharConfirmacao);

confirmOkBtn.addEventListener("click", function() {
    if (acaoPendente) {
        const fn = acaoPendente;
        fecharConfirmacao();
        fn();
    }
});

formLimparProdutividade.addEventListener("submit", function(e) {
    e.preventDefault();
    abrirConfirmacao(
        "Tem certeza que deseja apagar TODOS os dados de produtividade? Essa ação não pode ser desfeita.",
        () => formLimparProdutividade.submit()
    );
});
</script>

</body>
</html>