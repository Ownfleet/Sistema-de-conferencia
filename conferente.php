<?php
require "db.php";

$quantidadeMesas = 24;

function parseMotoFormulaPhp(?string $formula): array {
    $formula = trim((string)$formula);
    $resultado = [];

    if ($formula === "") {
        return $resultado;
    }

    preg_match_all('/([A-Z]-\d+)\((\d+)\)/i', $formula, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $resultado[] = [
            "cluster_code" => trim((string)$m[1]),
            "packages" => (int)$m[2]
        ];
    }

    return $resultado;
}

$stmt = $pdo->query("
    SELECT
        d.id,
        d.driver_id,
        d.driver_name,
        d.cluster_text,
        d.packages_total,
        d.vehicle_type,
        d.status,
        d.moto_formula,
        dc.cluster_code,
        dc.packages AS cluster_packages,
        dc.sort_order
    FROM drivers d
    LEFT JOIN driver_clusters dc ON dc.driver_ref = d.id
    WHERE d.active = true
    ORDER BY d.driver_name, dc.sort_order, dc.id
");
$rows = $stmt->fetchAll();

$driversMap = [];

foreach ($rows as $r) {
    $driverDbId = $r["id"];

    if (!isset($driversMap[$driverDbId])) {
        $motoItems = parseMotoFormulaPhp($r["moto_formula"] ?? "");

        $driversMap[$driverDbId] = [
            "id" => $r["id"],
            "driver_id" => $r["driver_id"],
            "driver_name" => $r["driver_name"],
            "cluster_text" => $r["cluster_text"],
            "packages_total" => (int)$r["packages_total"],
            "vehicle_type" => $r["vehicle_type"],
            "status" => $r["status"],
            "moto_formula" => $r["moto_formula"],
            "moto_items" => $motoItems,
            "clusters" => []
        ];
    }

    if (!empty($r["cluster_code"])) {
        $driversMap[$driverDbId]["clusters"][] = [
            "cluster_code" => $r["cluster_code"],
            "packages" => (int)$r["cluster_packages"],
            "sort_order" => (int)$r["sort_order"]
        ];
    }
}

$totaisPorRota = [];
$restantesPorRota = [];

foreach ($driversMap as $driver) {
    $rota = trim((string)$driver["cluster_text"]);
    if ($rota === "") continue;

    if (!isset($totaisPorRota[$rota])) $totaisPorRota[$rota] = 0;
    if (!isset($restantesPorRota[$rota])) $restantesPorRota[$rota] = 0;

    $totaisPorRota[$rota]++;

    if (mb_strtolower((string)($driver["status"] ?? "")) !== "finalizado") {
        $restantesPorRota[$rota]++;
    }
}

$driversByDriverId = [];
foreach ($driversMap as $driver) {
    $driver["route_total"] = $totaisPorRota[$driver["cluster_text"]] ?? 0;
    $driver["route_restantes"] = $restantesPorRota[$driver["cluster_text"]] ?? 0;
    $driver["route_mates"] = [];

    foreach ($driversMap as $other) {
        if ($other["driver_id"] === $driver["driver_id"]) continue;

        if (($other["cluster_text"] ?? "") === ($driver["cluster_text"] ?? "")) {
            $driver["route_mates"][] = [
                "id" => $other["id"],
                "driver_id" => $other["driver_id"],
                "driver_name" => $other["driver_name"],
                "status" => $other["status"],
                "vehicle_type" => $other["vehicle_type"],
                "packages_total" => $other["packages_total"]
            ];
        }
    }

    $driversByDriverId[$driver["driver_id"]] = $driver;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel Conferente</title>
<style>
:root{
    --shopee:#ee4d2d;
    --shopee-2:#ff6a3d;
    --shopee-dark:#c83f22;
    --navy:#0f172a;
    --navy-2:#16213e;
    --bg:#f6f8fc;
    --bg-soft:#eef2f7;
    --card:#ffffff;
    --line:#e5e7eb;
    --line-soft:#edf1f6;
    --text:#1f2937;
    --muted:#667085;
    --ok:#16a34a;
    --ok-bg:#ecfdf3;
    --warn:#f59e0b;
    --warn-bg:#fff7ed;
    --bad:#dc2626;
    --bad-bg:#fff1f2;
    --mesa:#2563eb;
    --mesa-bg:#eff6ff;
    --mesa-line:#93c5fd;
    --shadow-sm:0 10px 24px rgba(15,23,42,.06);
    --shadow-md:0 18px 38px rgba(15,23,42,.12);
    --radius:24px;
    --radius-md:18px;
    --radius-sm:14px;
}

*{ box-sizing:border-box; }

html, body{
    height:100%;
}

body{
    margin:0;
    font-family:Arial,sans-serif;
    background:
        radial-gradient(circle at top left, #ffffff 0%, var(--bg) 38%, var(--bg-soft) 100%);
    color:var(--text);
}

body.modal-open{
    overflow:hidden;
}

.topo{
    background:linear-gradient(90deg, #8f3b2f 0%, #a04535 100%);
    color:#fff;
    padding:20px 28px;
    box-shadow:0 10px 28px rgba(143,59,47,.20);
    position:sticky;
    top:0;
    z-index:20;
}

.topo h2{
    margin:0;
    font-size:30px;
    font-weight:900;
    letter-spacing:.2px;
}

.container{
    padding:20px;
}

.box-mesas{
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    border-radius:28px;
    padding:24px;
    box-shadow:var(--shadow-sm);
    border:1px solid var(--line-soft);
}

.box-mesas h3{
    margin:0 0 18px 0;
    font-size:24px;
    color:var(--navy);
    font-weight:900;
}

.mesas-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(128px, 128px));
    gap:16px;
    justify-content:flex-start;
}

.btn-mesa{
    width:128px;
    height:128px;
    border:none;
    border-radius:26px;
    background:linear-gradient(145deg,#11204a 0%, #18316d 100%);
    color:#fff;
    cursor:pointer;
    font-weight:900;
    font-size:22px;
    line-height:1.2;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:12px;
    transition:transform .20s ease, box-shadow .20s ease, filter .20s ease;
    box-shadow:
        0 16px 28px rgba(15,27,61,.26),
        inset 0 1px 0 rgba(255,255,255,.10),
        inset 0 -6px 12px rgba(0,0,0,.18);
    border:2px solid rgba(255,255,255,.08);
    position:relative;
    overflow:hidden;
}

.btn-mesa::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,0));
    pointer-events:none;
}

.btn-mesa:hover{
    transform:translateY(-4px) scale(1.02);
    box-shadow:
        0 22px 36px rgba(15,27,61,.30),
        inset 0 1px 0 rgba(255,255,255,.12),
        inset 0 -6px 12px rgba(0,0,0,.20);
}

.btn-mesa:active{
    transform:translateY(-1px) scale(.99);
}

.btn-mesa.com-busca{
    background:linear-gradient(145deg,#d4583c 0%, #ee4d2d 100%);
    box-shadow:
        0 16px 30px rgba(238,77,45,.28),
        inset 0 1px 0 rgba(255,255,255,.14),
        inset 0 -5px 10px rgba(0,0,0,.14);
}

/* MODAL */
.modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.58);
    backdrop-filter:blur(4px);
    display:none;
    z-index:9999;
    padding:14px;
}

.modal.ativo{
    display:block;
    animation:fadeIn .18s ease;
}

.modal-card{
    width:100%;
    height:calc(100vh - 28px);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    border-radius:30px;
    box-shadow:0 24px 70px rgba(15,23,42,.28);
    padding:18px;
    display:grid;
    grid-template-rows:auto auto 1fr;
    gap:14px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.65);
}

.modal-topo{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.modal-topo h3{
    margin:0;
    font-size:28px;
    color:var(--navy);
    font-weight:900;
}

.btn-fechar{
    border:none;
    background:linear-gradient(135deg,var(--navy) 0%, #09101f 100%);
    color:#fff;
    width:50px;
    height:50px;
    border-radius:16px;
    cursor:pointer;
    font-size:24px;
    font-weight:bold;
    flex:0 0 50px;
    box-shadow:0 10px 22px rgba(15,23,42,.22);
    transition:transform .15s ease, filter .15s ease;
}

.btn-fechar:hover{
    transform:translateY(-1px);
    filter:brightness(1.05);
}

.busca-modal{
    display:grid;
    grid-template-columns:minmax(300px, 1fr) 150px 160px;
    gap:12px;
    align-items:center;
}

.busca-modal input{
    width:100%;
    padding:16px 16px;
    border:1px solid #d7dce5;
    border-radius:18px;
    font-size:18px;
    min-width:0;
    background:#fff;
    box-shadow:inset 0 1px 2px rgba(0,0,0,.02);
}

.busca-modal input:focus{
    outline:none;
    border-color:#ffb39f;
    box-shadow:0 0 0 4px rgba(238,77,45,.10);
}

.busca-modal button{
    border:none;
    border-radius:18px;
    padding:15px 18px;
    font-weight:900;
    cursor:pointer;
    color:#fff;
    font-size:16px;
    height:56px;
    transition:transform .15s ease, filter .15s ease, box-shadow .15s ease;
    box-shadow:0 10px 22px rgba(0,0,0,.10);
}

.busca-modal button:hover{
    transform:translateY(-1px);
    filter:brightness(1.03);
}

.btn-localizar{
    background:linear-gradient(90deg,var(--shopee) 0%,var(--shopee-2) 100%);
}

.btn-limpar-mesa{
    background:linear-gradient(135deg,#6b7a90 0%, #5a687d 100%);
}

.modal-conteudo{
    min-height:0;
    display:grid;
    grid-template-columns:1.2fr .95fr;
    gap:16px;
    overflow:hidden;
}

.coluna-principal,
.coluna-lateral{
    min-height:0;
    display:flex;
    flex-direction:column;
    gap:16px;
}

.resultado-mesa{
    flex:1 1 auto;
    border:1px solid var(--line);
    border-radius:26px;
    padding:18px;
    background:linear-gradient(180deg,#f8fafc 0%,#f6f9fc 100%);
    overflow:auto;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.7);
}

.resultado-mesa.destacado-conferindo{
    border:2px solid var(--warn);
    box-shadow:0 0 0 4px rgba(245,158,11,0.12);
}

.resultado-wrap{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.resultado-topo{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
    align-items:start;
}

.resultado-mesa .titulo{
    font-size:15px;
    color:#6b7280;
    margin-bottom:4px;
    font-weight:700;
}

.nome{
    font-size:22px;
    font-weight:900;
    margin:6px 0 10px 0;
    color:#111827;
    line-height:1.15;
}

.info-basica{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px 14px;
}

.linha{
    color:#374151;
    font-size:16px;
    line-height:1.35;
}

.pacote-destaque{
    background:linear-gradient(90deg,#fff4ef 0%,#fff 100%);
    border:2px solid #ffd5c7;
    color:#9a3412;
    border-radius:20px;
    padding:18px 20px;
    font-size:clamp(22px, 2vw, 32px);
    font-weight:900;
    text-align:center;
    letter-spacing:.4px;
    box-shadow:0 8px 20px rgba(238,77,45,.06);
}

.rota-super-destaque{
    background:#fff;
    border:2px solid #cfd8e3;
    border-radius:20px;
    padding:20px 18px;
    text-align:center;
    font-size:clamp(22px, 2vw, 34px);
    font-weight:900;
    letter-spacing:.8px;
    color:#0f172a;
    word-break:break-word;
    box-shadow:0 8px 20px rgba(15,23,42,.04);
}

.info-rota-normal h4{
    margin:0 0 10px 0;
    font-size:18px;
    color:#111827;
    font-weight:900;
}

.moto-box{
    padding:16px;
    border-radius:20px;
    background:linear-gradient(180deg,#fff6f1 0%,#fff2eb 100%);
    border:1px solid #ffd5c7;
}

.moto-titulo{
    font-weight:900;
    color:#7c2d12;
    margin-bottom:12px;
    font-size:18px;
}

.moto-lista{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
}

.moto-item{
    background:#fff;
    border:1px solid #ffd5c7;
    border-radius:16px;
    padding:14px 16px;
    font-weight:900;
    color:#111827;
    font-size:clamp(16px, 1.6vw, 19px);
    line-height:1.35;
    word-break:break-word;
    box-shadow:0 6px 14px rgba(238,77,45,.04);
}

.moto-item .numero{
    font-size:clamp(24px, 2vw, 30px);
    color:#111827;
}

.moto-item .cluster{
    font-size:clamp(22px, 1.9vw, 24px);
    color:#7c2d12;
}

.moto-total{
    margin-top:12px;
    background:#fff;
    border:2px solid #ffd5c7;
    border-radius:16px;
    padding:16px;
    font-size:clamp(20px, 1.8vw, 24px);
    font-weight:900;
    color:#7c2d12;
    text-align:center;
}

.alerta-conflito-mesa{
    padding:14px 16px;
    border-radius:16px;
    background:var(--bad-bg);
    color:#b91c1c;
    border:1px solid #fecdd3;
    font-weight:900;
    font-size:16px;
    line-height:1.4;
}

.acoes-mesa{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 220px));
    gap:12px;
    align-items:stretch;
}

.btn-acao-mesa{
    border:none;
    border-radius:16px;
    padding:14px 18px;
    color:#fff;
    cursor:pointer;
    font-weight:900;
    font-size:17px;
    min-height:56px;
    transition:transform .15s ease, filter .15s ease, box-shadow .15s ease;
    box-shadow:0 10px 22px rgba(0,0,0,.10);
}

.btn-acao-mesa:hover{
    transform:translateY(-1px);
    filter:brightness(1.03);
}

.btn-acao-conferindo{
    background:linear-gradient(135deg,#f59e0b 0%, #fbbf24 100%);
}

.btn-acao-finalizado{
    background:linear-gradient(135deg,#64748b 0%, #475569 100%);
}

.btn-acao-mesa:disabled{
    opacity:.7;
    cursor:not-allowed;
}

.status-finalizado-msg{
    padding:16px;
    border-radius:16px;
    background:#e5e7eb;
    color:#111827;
    font-weight:900;
    font-size:18px;
    line-height:1.4;
}

.feedback-status{
    padding:12px 14px;
    border-radius:14px;
    font-weight:900;
    font-size:15px;
}

.feedback-status.conferindo{
    background:#fff7e6;
    color:#92400e;
    border:1px solid #f59e0b;
}

.feedback-status.finalizado{
    background:#eef2f7;
    color:#111827;
    border:1px solid #9ca3af;
}

.msg-vazia{
    color:#6b7280;
    font-size:18px;
    text-align:center;
    padding:26px 10px;
    min-height:140px;
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1.4;
}

.bloco-lateral{
    background:linear-gradient(180deg,#f8fafc 0%,#f6f9fc 100%);
    border:1px solid var(--line);
    border-radius:26px;
    padding:18px;
    display:flex;
    flex-direction:column;
    min-height:0;
    overflow:auto;
}

.bloco-lateral h4{
    margin:0 0 14px 0;
    font-size:22px;
    color:#111827;
    font-weight:900;
}

.lista-companheiros{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.item-companheiro{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    border-radius:16px;
    padding:14px 16px;
    border:1px solid #e5e7eb;
    background:#fff;
    transition:transform .15s ease, box-shadow .15s ease;
}

.item-companheiro:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 18px rgba(15,23,42,.06);
}

.item-companheiro.finalizado{
    background:#eef2f7;
    color:#64748b;
}

.item-companheiro.pendente{
    background:#f0fdf4;
    border-color:#86efac;
    box-shadow:0 0 0 3px rgba(34,197,94,.10);
}

.item-companheiro.mesa-atual{
    background:var(--mesa-bg);
    border-color:var(--mesa-line);
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

.item-companheiro .esq{
    display:flex;
    align-items:center;
    gap:12px;
}

.luz{
    width:16px;
    height:16px;
    border-radius:50%;
    flex:0 0 16px;
}

.luz.acesa{
    background:#22c55e;
    box-shadow:0 0 12px rgba(34,197,94,.9), 0 0 24px rgba(34,197,94,.45);
}

.luz.apagada{
    background:#9ca3af;
}

.luz.mesa{
    background:#2563eb;
    box-shadow:0 0 12px rgba(37,99,235,.9), 0 0 24px rgba(37,99,235,.45);
}

.nome-companheiro{
    font-weight:900;
    font-size:17px;
    color:#111827;
    line-height:1.3;
}

.item-companheiro.finalizado .nome-companheiro{
    color:#6b7280;
    text-decoration:line-through;
}

.status-chip{
    padding:8px 12px;
    border-radius:999px;
    font-weight:900;
    font-size:13px;
    white-space:nowrap;
}

.status-chip.pendente{
    background:#dcfce7;
    color:#166534;
}

.status-chip.finalizado{
    background:#e5e7eb;
    color:#4b5563;
}

.status-chip.mesa{
    background:#dbeafe;
    color:#1d4ed8;
}

.cronometro-box{
    background:linear-gradient(135deg,#0f172a 0%,#16213e 100%);
    color:#fff;
    border-radius:24px;
    padding:18px;
    box-shadow:0 14px 28px rgba(15,23,42,.18);
    border:1px solid rgba(255,255,255,.06);
}

.cronometro-label{
    font-size:13px;
    opacity:.82;
    margin-bottom:8px;
    font-weight:700;
    letter-spacing:.3px;
}

.cronometro-tempo{
    font-size:clamp(30px, 3vw, 42px);
    font-weight:900;
    letter-spacing:1px;
}

.cronometro-rota{
    margin-top:8px;
    font-size:15px;
    opacity:.92;
    line-height:1.35;
    word-break:break-word;
}

.loading-overlay{
    position:fixed;
    inset:0;
    background:rgba(255,255,255,0.88);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:10000;
    transition:opacity .2s ease;
}

.loading-overlay.hidden{
    opacity:0;
    pointer-events:none;
}

.loading-box{
    background:#fff;
    border:1px solid #eee;
    border-radius:20px;
    padding:24px 28px;
    box-shadow:0 15px 40px rgba(0,0,0,0.12);
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:14px;
    min-width:220px;
}

.spinner{
    width:46px;
    height:46px;
    border:4px solid #f3f4f6;
    border-top-color:var(--shopee);
    border-radius:50%;
    animation:spin 1s linear infinite;
}

.loading-text{
    font-size:16px;
    font-weight:900;
    color:#111827;
}

@keyframes spin{
    to{ transform:rotate(360deg); }
}
@keyframes fadeIn{
    from{ opacity:0; }
    to{ opacity:1; }
}

@media (max-width: 1220px){
    .modal-card{
        height:calc(100vh - 20px);
        padding:14px;
    }
    .modal-conteudo{
        grid-template-columns:1fr;
    }
    .coluna-principal,
    .coluna-lateral{
        overflow:auto;
    }
    .busca-modal{
        grid-template-columns:1fr 1fr;
    }
    .busca-modal input{
        grid-column:1 / -1;
    }
}

@media (max-width: 760px){
    .topo{
        padding:18px 16px;
    }
    .topo h2{
        font-size:24px;
    }
    .container{
        padding:14px;
    }
    .modal{
        padding:8px;
    }
    .modal-card{
        height:calc(100vh - 16px);
        border-radius:22px;
        padding:12px;
    }
    .modal-topo h3{
        font-size:22px;
    }
    .busca-modal{
        grid-template-columns:1fr;
    }
    .resultado-mesa,
    .bloco-lateral{
        padding:14px;
    }
    .info-basica{
        grid-template-columns:1fr;
    }
    .acoes-mesa{
        grid-template-columns:1fr;
    }
    .item-companheiro{
        flex-direction:column;
        align-items:flex-start;
    }
    .mesas-grid{
        grid-template-columns:repeat(auto-fit, minmax(108px, 108px));
        gap:12px;
    }
    .btn-mesa{
        width:108px;
        height:108px;
        font-size:18px;
        border-radius:20px;
    }
}
</style>
</head>
<body>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <div class="spinner"></div>
        <div class="loading-text">Carregando painel...</div>
    </div>
</div>

<div class="topo">
    <h2>Painel Conferente</h2>
</div>

<div class="container">
    <div class="box-mesas">
        <h3>Mesas de atendimento</h3>
        <div class="mesas-grid">
            <?php for ($i = 1; $i <= $quantidadeMesas; $i++): ?>
                <button type="button" class="btn-mesa" data-mesa="<?= $i ?>">
                    Mesa <?= $i ?>
                </button>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="modal" id="modalMesa">
    <div class="modal-card">
        <div class="modal-topo">
            <h3 id="tituloMesa">Mesa</h3>
            <button type="button" class="btn-fechar" id="fecharModal">×</button>
        </div>

        <div class="busca-modal">
            <input type="text" id="inputMesa" placeholder="Digite o ID do motorista">
            <button type="button" class="btn-localizar" id="btnPesquisarMesa">Pesquisar</button>
            <button type="button" class="btn-limpar-mesa" id="btnLimparMesa">Limpar mesa</button>
        </div>

        <div class="modal-conteudo">
            <div class="coluna-principal">
                <div id="resultadoMesa" class="resultado-mesa">
                    <div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>
                </div>
            </div>

            <div class="coluna-lateral">
                <div id="cronometroMesa" class="cronometro-box" style="display:none;">
                    <div class="cronometro-label">TEMPO DE CONFERÊNCIA</div>
                    <div class="cronometro-tempo" id="cronometroTempo">00:00:00</div>
                    <div class="cronometro-rota" id="cronometroRota"></div>
                </div>

                <div class="bloco-lateral">
                    <h4>Motoristas da mesma rota</h4>
                    <div id="companheirosMesa" class="lista-companheiros">
                        <div class="msg-vazia" style="min-height:auto; padding:10px 0;">Nenhuma rota carregada.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const DRIVERS = <?= json_encode($driversByDriverId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const modal = document.getElementById("modalMesa");
const tituloMesa = document.getElementById("tituloMesa");
const inputMesa = document.getElementById("inputMesa");
const resultadoMesa = document.getElementById("resultadoMesa");
const companheirosMesa = document.getElementById("companheirosMesa");
const fecharModal = document.getElementById("fecharModal");
const btnPesquisarMesa = document.getElementById("btnPesquisarMesa");
const btnLimparMesa = document.getElementById("btnLimparMesa");
const botoesMesa = document.querySelectorAll(".btn-mesa");
const loadingOverlay = document.getElementById("loadingOverlay");
const cronometroMesa = document.getElementById("cronometroMesa");
const cronometroTempo = document.getElementById("cronometroTempo");
const cronometroRota = document.getElementById("cronometroRota");

let mesaAtual = null;
let cronometroInterval = null;
let cronometroInicio = null;
let cronometroRotaAtual = "";

function mostrarLoading(texto = "Carregando painel..."){
    const textoEl = loadingOverlay.querySelector(".loading-text");
    if (textoEl) textoEl.textContent = texto;
    loadingOverlay.classList.remove("hidden");
}

function esconderLoading(){
    loadingOverlay.classList.add("hidden");
}

window.addEventListener("load", function(){
    setTimeout(() => esconderLoading(), 250);
});

function chaveMesa(mesa){
    return "mesa_conferente_" + mesa;
}

function atualizarBotoesMesas(){
    botoesMesa.forEach(btn => {
        const mesa = btn.dataset.mesa;
        const salvo = localStorage.getItem(chaveMesa(mesa));
        if (salvo) {
            btn.classList.add("com-busca");
        } else {
            btn.classList.remove("com-busca");
        }
    });
}

function abrirMesa(mesa){
    mesaAtual = mesa;
    tituloMesa.textContent = "Mesa " + mesa;
    modal.classList.add("ativo");
    document.body.classList.add("modal-open");

    const salvo = localStorage.getItem(chaveMesa(mesa));
    inputMesa.value = salvo || "";

    renderResultadoMesa(salvo || "");
    carregarCronometroMesa();
    setTimeout(() => inputMesa.focus(), 50);
}

function fecharMesa(){
    modal.classList.remove("ativo");
    document.body.classList.remove("modal-open");
    mesaAtual = null;
}

function salvarMesa(valor){
    if (!mesaAtual) return;

    const limpo = (valor || "").trim();

    if (limpo === "") {
        localStorage.removeItem(chaveMesa(mesaAtual));
    } else {
        localStorage.setItem(chaveMesa(mesaAtual), limpo);
    }

    atualizarBotoesMesas();
}

function escaparHtml(str){
    return String(str ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function formatarDuracao(segundos){
    const s = Math.max(0, parseInt(segundos || 0, 10));
    const h = String(Math.floor(s / 3600)).padStart(2, "0");
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, "0");
    const sec = String(s % 60).padStart(2, "0");
    return `${h}:${m}:${sec}`;
}

function pararCronometroVisual(){
    if (cronometroInterval) {
        clearInterval(cronometroInterval);
        cronometroInterval = null;
    }
    cronometroInicio = null;
    cronometroRotaAtual = "";
    cronometroMesa.style.display = "none";
    cronometroTempo.textContent = "00:00:00";
    cronometroRota.textContent = "";
}

function iniciarCronometroVisual(dataInicio, rotaTexto){
    if (cronometroInterval) clearInterval(cronometroInterval);

    cronometroInicio = new Date(dataInicio);
    cronometroRotaAtual = rotaTexto || "";

    cronometroMesa.style.display = "block";
    cronometroRota.textContent = cronometroRotaAtual ? `Rota em conferência: ${cronometroRotaAtual}` : "";

    const tick = () => {
        const agora = new Date();
        const total = Math.floor((agora.getTime() - cronometroInicio.getTime()) / 1000);
        cronometroTempo.textContent = formatarDuracao(total);
    };

    tick();
    cronometroInterval = setInterval(tick, 1000);
}

async function carregarCronometroMesa(){
    if (!mesaAtual) return;

    try {
        const resposta = await fetch(`tempo_mesa.php?action=status&mesa=${encodeURIComponent(mesaAtual)}`);
        const texto = await resposta.text();
        const data = JSON.parse(texto);

        if (data.ok && data.ativo && data.registro) {
            iniciarCronometroVisual(data.registro.started_at, data.registro.rota_texto);
        } else {
            pararCronometroVisual();
        }
    } catch (e) {
        console.error("Erro ao carregar cronômetro da mesa:", e);
        pararCronometroVisual();
    }
}

async function apiMesa(action, payload = {}, method = "POST"){
    let res;

    if (method === "GET") {
        const params = new URLSearchParams({ action, ...payload });
        res = await fetch("mesa_controle.php?" + params.toString());
    } else {
        const form = new FormData();
        form.append("action", action);

        Object.keys(payload).forEach(key => {
            form.append(key, payload[key]);
        });

        res = await fetch("mesa_controle.php", {
            method: "POST",
            body: form
        });
    }

    const texto = await res.text();

    let json;
    try {
        json = JSON.parse(texto);
    } catch (e) {
        throw new Error("Resposta inválida do mesa_controle.php: " + texto);
    }

    if (!res.ok) {
        throw new Error(json.erro || ("Erro HTTP " + res.status));
    }

    return json;
}

async function verificarConflitoMesa(rotaTexto){
    if (!mesaAtual || !rotaTexto) return null;

    try {
        const json = await apiMesa("check_conflict", {
            mesa: mesaAtual,
            rota_texto: rotaTexto
        }, "GET");

        if (json.ok && json.conflito) return json;
        return null;
    } catch (e) {
        console.error("Erro ao verificar conflito:", e.message);
        return null;
    }
}

function obterMotoItemsIndividuais(motorista){
    const formulaItems = Array.isArray(motorista.moto_items) ? motorista.moto_items : [];
    if (formulaItems.length > 0) {
        return formulaItems;
    }

    const clusters = Array.isArray(motorista.clusters) ? motorista.clusters : [];
    return clusters;
}

function montarCompanheirosDaRotaHtml(motorista){
    const todosDaRota = Object.values(DRIVERS).filter(item =>
        (item.cluster_text || "") === (motorista.cluster_text || "")
    );

    if (todosDaRota.length <= 1) {
        return '<div class="msg-vazia" style="min-height:auto; padding:10px 0;">Nenhum outro motorista nessa rota.</div>';
    }

    let html = "";

    todosDaRota.forEach(item => {
        const ehMotoristaDaMesa = String(item.driver_id) === String(motorista.driver_id);
        const finalizado = (item.status || "").toLowerCase() === "finalizado";

        let classe = "pendente";
        let luz = "acesa";
        let statusTexto = "Falta carregar";

        if (ehMotoristaDaMesa) {
            classe = "mesa-atual";
            luz = "mesa";
            statusTexto = "Motorista na sua mesa";
        } else if (finalizado) {
            classe = "finalizado";
            luz = "apagada";
            statusTexto = "Já carregou";
        }

        html += `
            <div class="item-companheiro ${classe}">
                <div class="esq">
                    <span class="luz ${luz}"></span>
                    <div>
                        <div class="nome-companheiro">${escaparHtml(item.driver_name)}</div>
                        <div>ID: ${escaparHtml(item.driver_id)} • ${escaparHtml(item.vehicle_type || "")}</div>
                    </div>
                </div>
                <div class="status-chip ${ehMotoristaDaMesa ? "mesa" : classe}">
                    ${statusTexto}
                </div>
            </div>
        `;
    });

    return html;
}

async function renderResultadoMesa(idBuscado){
    const id = (idBuscado || "").trim();

    if (!id) {
        resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>';
        companheirosMesa.innerHTML = '<div class="msg-vazia" style="min-height:auto; padding:10px 0;">Nenhuma rota carregada.</div>';
        return;
    }

    const motorista = DRIVERS[id];

    if (!motorista) {
        resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista encontrado para o ID informado.</div>';
        companheirosMesa.innerHTML = '<div class="msg-vazia" style="min-height:auto; padding:10px 0;">Nenhuma rota carregada.</div>';
        return;
    }

    const ehMoto = (motorista.vehicle_type || "").toUpperCase() === "MOTO";
    const motoItems = obterMotoItemsIndividuais(motorista);

    let htmlClusters = "";

    if (ehMoto && motoItems.length > 0) {
        htmlClusters += '<div class="moto-box">';
        htmlClusters += '<div class="moto-titulo">O QUE PASSAR PARA ESTE MOTORISTA</div>';
        htmlClusters += '<div class="moto-lista">';

        motoItems.forEach(c => {
            const packages = parseInt(c.packages || 0, 10);

            if (packages > 0) {
                htmlClusters += `
                    <div class="moto-item">
                        PASSE <span class="numero">${packages}</span> DA <span class="cluster">${escaparHtml(c.cluster_code)}</span>
                    </div>
                `;
            }
        });

        htmlClusters += '</div>';
        htmlClusters += `<div class="moto-total">TOTAL ${escaparHtml(motorista.packages_total)} PACOTES</div>`;
        htmlClusters += '</div>';
    }

    const blocoRotaNormal = !ehMoto
        ? `
            <div class="info-rota-normal">
                <h4>ROTA</h4>
                <div class="rota-super-destaque">${escaparHtml(motorista.cluster_text)}</div>
            </div>
        `
        : "";

    const totalPacotes = `
        <div class="pacote-destaque">
            TOTAL A PASSAR: ${escaparHtml(motorista.packages_total)} PACOTES
        </div>
    `;

    let htmlAcoes = "";

    if ((motorista.status || "").toLowerCase() !== "finalizado") {
        htmlAcoes = `
            <div class="acoes-mesa">
                <button type="button" class="btn-acao-mesa btn-acao-conferindo" onclick="alterarStatusMesa('${escaparHtml(motorista.driver_id)}','conferindo')">
                    Conferindo
                </button>
                <button type="button" class="btn-acao-mesa btn-acao-finalizado" onclick="alterarStatusMesa('${escaparHtml(motorista.driver_id)}','finalizado')">
                    Finalizado
                </button>
            </div>
        `;
    } else {
        htmlAcoes = `
            <div class="status-finalizado-msg">
                Esta rota já foi finalizada. Para reabrir, altere pelo painel admin.
            </div>
        `;
    }

    const conflito = await verificarConflitoMesa(motorista.cluster_text);
    const htmlConflito = conflito
        ? `<div class="alerta-conflito-mesa">${escaparHtml(conflito.mensagem)}</div>`
        : "";

    resultadoMesa.innerHTML = `
        <div class="resultado-wrap">
            <div class="resultado-topo">
                <div>
                    <div class="titulo">Resultado encontrado</div>
                    <div class="linha"><strong>ID:</strong> ${escaparHtml(motorista.driver_id)}</div>
                    <div class="nome">${escaparHtml(motorista.driver_name)}</div>
                </div>
            </div>

            <div class="info-basica">
                <div class="linha"><strong>Rota:</strong> ${escaparHtml(motorista.cluster_text)}</div>
                <div class="linha"><strong>Veículo:</strong> ${escaparHtml(motorista.vehicle_type)}</div>
                <div class="linha"><strong>Status:</strong> <span id="statusMesaAtual">${escaparHtml(motorista.status)}</span></div>
                <div class="linha"><strong>Companheiros na rota:</strong> ${escaparHtml(motorista.route_total || 1)}</div>
            </div>

            ${totalPacotes}
            ${blocoRotaNormal}
            ${htmlClusters}
            ${htmlConflito}
            ${htmlAcoes}
        </div>
    `;

    companheirosMesa.innerHTML = montarCompanheirosDaRotaHtml(motorista);
}

async function alterarStatusMesa(driverId, novoStatus){
    const motorista = DRIVERS[driverId];

    if (!motorista) {
        alert("Motorista não encontrado.");
        return;
    }

    const botoes = resultadoMesa.querySelectorAll(".btn-acao-mesa");
    botoes.forEach(btn => btn.disabled = true);

    const feedbackAnterior = resultadoMesa.querySelector(".feedback-status");
    if (feedbackAnterior) feedbackAnterior.remove();

    if (novoStatus === "conferindo") {
        try {
            const conflito = await apiMesa("set_conferindo", {
                mesa: mesaAtual,
                driver_db_id: motorista.id,
                driver_id: motorista.driver_id,
                rota_texto: motorista.cluster_text
            });

            if (!conflito.ok) {
                resultadoMesa.innerHTML += `<div class="alerta-conflito-mesa">${escaparHtml(conflito.mensagem || "Essa rota já está em outra mesa.")}</div>`;
                botoes.forEach(btn => btn.disabled = false);
                return;
            }

            const formTempo = new FormData();
            formTempo.append("action", "start");
            formTempo.append("mesa", mesaAtual);
            formTempo.append("driver_ref", motorista.id);
            formTempo.append("driver_id", motorista.driver_id);
            formTempo.append("driver_name", motorista.driver_name);
            formTempo.append("rota_texto", motorista.cluster_text);
            formTempo.append("vehicle_type", motorista.vehicle_type);

            const respTempo = await fetch("tempo_mesa.php", {
                method: "POST",
                body: formTempo
            });

            const txtTempo = await respTempo.text();
            const jsonTempo = JSON.parse(txtTempo);

            if (!jsonTempo.ok) {
                alert(jsonTempo.erro || "Erro ao iniciar cronômetro.");
                botoes.forEach(btn => btn.disabled = false);
                return;
            }

            iniciarCronometroVisual(jsonTempo.started_at, motorista.cluster_text);

        } catch (e) {
            alert("Erro ao verificar conflito da mesa: " + e.message);
            botoes.forEach(btn => btn.disabled = false);
            return;
        }
    }

    try {
        const form = new FormData();
        form.append("id", motorista.id);
        form.append("status", novoStatus);

        const resposta = await fetch("atualizar_status.php", {
            method: "POST",
            body: form
        });

        const texto = await resposta.text();

        let json;
        try {
            json = JSON.parse(texto);
        } catch (e) {
            throw new Error("Resposta inválida do atualizar_status.php: " + texto);
        }

        if (!json.ok) {
            alert(json.erro || "Erro ao atualizar status.");
            botoes.forEach(btn => btn.disabled = false);
            return;
        }

        DRIVERS[driverId].status = novoStatus;

        await renderResultadoMesa(driverId);

        if (novoStatus === "conferindo") {
            resultadoMesa.classList.add("destacado-conferindo");
            const feedback = document.createElement("div");
            feedback.className = "feedback-status conferindo";
            feedback.textContent = "Motorista em conferência.";
            resultadoMesa.appendChild(feedback);
        }

        if (novoStatus === "finalizado") {
            resultadoMesa.classList.remove("destacado-conferindo");

            try {
                const formTempoFim = new FormData();
                formTempoFim.append("action", "finish");
                formTempoFim.append("mesa", mesaAtual);
                formTempoFim.append("rota_texto", motorista.cluster_text);

                const respFim = await fetch("tempo_mesa.php", {
                    method: "POST",
                    body: formTempoFim
                });

                const txtFim = await respFim.text();
                const jsonFim = JSON.parse(txtFim);

                if (jsonFim.ok) {
                    pararCronometroVisual();

                    const feedbackTempo = document.createElement("div");
                    feedbackTempo.className = "feedback-status finalizado";
                    feedbackTempo.textContent = `Rota finalizada com sucesso. Tempo: ${formatarDuracao(jsonFim.duration_seconds)}`;
                    resultadoMesa.appendChild(feedbackTempo);
                }
            } catch (e) {
                console.error("Erro ao finalizar cronômetro:", e);
            }

            try {
                await apiMesa("clear", { mesa: mesaAtual });
            } catch (e) {
                console.error("Erro ao limpar mesa após finalizar:", e.message);
            }
        }

    } catch (e) {
        alert("Erro ao atualizar status: " + e.message);
        botoes.forEach(btn => btn.disabled = false);
    }
}

botoesMesa.forEach(btn => {
    btn.addEventListener("click", function(){
        abrirMesa(this.dataset.mesa);
    });
});

fecharModal.addEventListener("click", fecharMesa);

/* não fecha ao clicar fora nem no esc */

btnPesquisarMesa.addEventListener("click", async function(){
    const valor = inputMesa.value.trim();
    salvarMesa(valor);

    const motorista = DRIVERS[valor];
    if (motorista && mesaAtual) {
        try {
            await apiMesa("save_search", {
                mesa: mesaAtual,
                driver_db_id: motorista.id,
                driver_id: motorista.driver_id,
                rota_texto: motorista.cluster_text
            });
        } catch (e) {
            console.error("Erro ao salvar mesa:", e.message);
        }
    }

    await renderResultadoMesa(valor);
});

btnLimparMesa.addEventListener("click", async function(){
    inputMesa.value = "";
    salvarMesa("");
    if (mesaAtual) {
        try {
            await apiMesa("clear", { mesa: mesaAtual });
        } catch (e) {
            console.error("Erro ao limpar mesa:", e.message);
        }
    }
    pararCronometroVisual();
    resultadoMesa.classList.remove("destacado-conferindo");
    resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>';
    companheirosMesa.innerHTML = '<div class="msg-vazia" style="min-height:auto; padding:10px 0;">Nenhuma rota carregada.</div>';
});

inputMesa.addEventListener("keydown", async function(e){
    if (e.key === "Enter") {
        e.preventDefault();
        const valor = inputMesa.value.trim();
        salvarMesa(valor);

        const motorista = DRIVERS[valor];
        if (motorista && mesaAtual) {
            try {
                await apiMesa("save_search", {
                    mesa: mesaAtual,
                    driver_db_id: motorista.id,
                    driver_id: motorista.driver_id,
                    rota_texto: motorista.cluster_text
                });
            } catch (e) {
                console.error("Erro ao salvar mesa:", e.message);
            }
        }

        await renderResultadoMesa(valor);
    }
});

atualizarBotoesMesas();
</script>

</body>
</html>
