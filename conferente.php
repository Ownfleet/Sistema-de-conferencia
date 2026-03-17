<?php
require "db.php";
session_start();

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$quantidadeMesas = 18;

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
        $driversMap[$driverDbId] = [
            "id" => $r["id"],
            "driver_id" => $r["driver_id"],
            "driver_name" => $r["driver_name"],
            "cluster_text" => $r["cluster_text"],
            "packages_total" => (int)$r["packages_total"],
            "vehicle_type" => $r["vehicle_type"],
            "status" => $r["status"],
            "moto_formula" => $r["moto_formula"],
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

    if (($driver["status"] ?? "") !== "finalizado") {
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
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(180deg,#f7f8fc 0%,#eef2f7 100%);
    color:#1f2937;
}
body.modal-open{
    overflow:hidden;
}
.topo{
    background:linear-gradient(90deg,#8f3b2f 0%,#a04535 100%);
    color:#fff;
    padding:22px 30px;
    box-shadow:0 8px 24px rgba(143,59,47,.18);
}
.topo h2{
    margin:0;
    font-size:30px;
    letter-spacing:.2px;
}
.container{
    padding:22px;
}
.box-mesas{
    background:#fff;
    border-radius:22px;
    padding:22px;
    margin-bottom:20px;
    box-shadow:0 10px 32px rgba(15,23,42,.06);
    border:1px solid #edf0f5;
}
.box-mesas h3{
    margin:0 0 18px 0;
    font-size:24px;
}

/* MESAS ATUALIZADAS */
.mesas-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(130px, 130px));
    gap:16px;
    justify-content:flex-start;
}
.btn-mesa{
    width:130px;
    height:130px;
    border:none;
    border-radius:24px;
    background:linear-gradient(145deg,#0f1b3d 0%, #15295c 100%);
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
    transition:all .22s ease;
    box-shadow:
        0 14px 28px rgba(15,27,61,.28),
        inset 0 1px 0 rgba(255,255,255,.10),
        inset 0 -4px 10px rgba(0,0,0,.18);
    border:2px solid rgba(255,255,255,.08);
    position:relative;
    overflow:hidden;
}
.btn-mesa::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,0));
    pointer-events:none;
}
.btn-mesa:hover{
    transform:translateY(-4px) scale(1.02);
    box-shadow:
        0 18px 34px rgba(15,27,61,.34),
        inset 0 1px 0 rgba(255,255,255,.12),
        inset 0 -4px 10px rgba(0,0,0,.20);
}
.btn-mesa:active{
    transform:translateY(-1px) scale(.99);
}
.btn-mesa.com-busca{
    background:linear-gradient(145deg,#a04535 0%, #c5563d 100%);
    box-shadow:
        0 14px 28px rgba(160,69,53,.30),
        inset 0 1px 0 rgba(255,255,255,.12),
        inset 0 -4px 10px rgba(0,0,0,.16);
}

.modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
    padding:20px;
    box-sizing:border-box;
    overflow:auto;
}
.modal.ativo{
    display:flex;
}
.modal-card{
    width:100%;
    max-width:980px;
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:24px;
    box-shadow:0 20px 60px rgba(15,23,42,.25);
    padding:22px;
}
.modal-topo{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px;
}
.modal-topo h3{
    margin:0;
    font-size:30px;
}
.btn-fechar{
    border:none;
    background:#0f172a;
    color:#fff;
    width:44px;
    height:44px;
    border-radius:14px;
    cursor:pointer;
    font-size:20px;
    font-weight:bold;
}
.busca-modal{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.busca-modal input{
    flex:1;
    min-width:220px;
    padding:15px 16px;
    border:1px solid #d7dce5;
    border-radius:14px;
    font-size:17px;
}
.busca-modal button{
    border:none;
    border-radius:14px;
    padding:14px 20px;
    font-weight:900;
    cursor:pointer;
    color:#fff;
    font-size:15px;
}
.btn-localizar{
    background:linear-gradient(90deg,#ee4d2d 0%,#ff6a3d 100%);
}
.btn-limpar-mesa{
    background:#64748b;
}
.resultado-mesa{
    border:1px solid #e5e7eb;
    border-radius:22px;
    padding:18px;
    background:#f8fafc;
}
.resultado-mesa.destacado-conferindo{
    border:2px solid #f59e0b;
    box-shadow:0 0 0 4px rgba(245,158,11,0.10);
}
.resultado-mesa .titulo{
    font-size:15px;
    color:#6b7280;
    margin-bottom:4px;
}
.nome{
    font-size:22px;
    font-weight:900;
    margin:6px 0 12px 0;
    color:#111827;
}
.linha{
    margin-bottom:8px;
    color:#374151;
    font-size:15px;
}
.pacote-destaque{
    margin-top:14px;
    background:linear-gradient(90deg,#fff4ef 0%,#fff 100%);
    border:2px solid #ffd5c7;
    color:#9a3412;
    border-radius:16px;
    padding:18px 20px;
    font-size:32px;
    font-weight:900;
    text-align:center;
    letter-spacing:.4px;
}
.rota-super-destaque{
    margin-top:14px;
    background:#fff;
    border:2px solid #cbd5e1;
    border-radius:16px;
    padding:20px 18px;
    text-align:center;
    font-size:34px;
    font-weight:900;
    letter-spacing:.8px;
    color:#0f172a;
}
.info-rota-normal{
    margin-top:16px;
}
.info-rota-normal h4{
    margin:0 0 12px 0;
    font-size:18px;
    color:#111827;
}
.moto-box{
    margin-top:16px;
    padding:16px;
    border-radius:18px;
    background:#fff4ef;
    border:1px solid #ffd5c7;
}
.moto-titulo{
    font-weight:900;
    color:#7c2d12;
    margin-bottom:12px;
    font-size:18px;
}
.moto-lista{
    display:flex;
    flex-direction:column;
    gap:10px;
}
.moto-item{
    background:#fff;
    border:1px solid #ffd5c7;
    border-radius:14px;
    padding:14px 16px;
    font-weight:900;
    color:#111827;
    font-size:19px;
}
.moto-item .numero{
    font-size:30px;
    color:#111827;
}
.moto-item .cluster{
    font-size:24px;
    color:#7c2d12;
}
.moto-total{
    margin-top:12px;
    background:#fff;
    border:2px solid #ffd5c7;
    border-radius:14px;
    padding:16px;
    font-size:26px;
    font-weight:900;
    color:#7c2d12;
    text-align:center;
}
.bloco-companheiros{
    margin-top:18px;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:16px;
}
.bloco-companheiros h4{
    margin:0 0 12px 0;
    font-size:20px;
    color:#111827;
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
    border-radius:14px;
    padding:14px 16px;
    border:1px solid #e5e7eb;
    background:#fff;
}
.item-companheiro.finalizado{
    background:#eef2f7;
    color:#64748b;
}
.item-companheiro.pendente{
    background:#f0fdf4;
    border-color:#86efac;
    box-shadow:0 0 0 3px rgba(34,197,94,.12);
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
.nome-companheiro{
    font-weight:900;
    font-size:17px;
    color:#111827;
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
.acoes-mesa{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
}
.btn-acao-mesa{
    border:none;
    border-radius:14px;
    padding:13px 18px;
    color:#fff;
    cursor:pointer;
    font-weight:900;
    font-size:16px;
}
.btn-acao-conferindo{
    background:#f59e0b;
}
.btn-acao-finalizado{
    background:#64748b;
}
.btn-acao-mesa:disabled{
    opacity:.7;
    cursor:not-allowed;
}
.status-finalizado-msg{
    margin-top:16px;
    padding:14px 16px;
    border-radius:14px;
    background:#e5e7eb;
    color:#111827;
    font-weight:900;
}
.feedback-status{
    margin-top:14px;
    padding:12px 14px;
    border-radius:12px;
    font-weight:900;
    font-size:15px;
}
.feedback-status.conferindo{
    background:#fff7e6;
    color:#92400e;
    border:1px solid #f59e0b;
}
.feedback-status.finalizado{
    background:#e5e7eb;
    color:#111827;
    border:1px solid #9ca3af;
}
.alerta-conflito-mesa{
    margin-top:14px;
    padding:14px 16px;
    border-radius:14px;
    background:#fff1f2;
    color:#b91c1c;
    border:1px solid #fecdd3;
    font-weight:900;
    font-size:16px;
}
.msg-vazia{
    color:#6b7280;
    font-size:16px;
    text-align:center;
    padding:26px 10px;
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
    border-radius:18px;
    padding:24px 28px;
    box-shadow:0 15px 40px rgba(0,0,0,0.12);
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:14px;
    min-width:220px;
}
.spinner{
    width:44px;
    height:44px;
    border:4px solid #f3f4f6;
    border-top-color:#ee4d2d;
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
@media (max-width: 700px){
    .topo{ padding:18px 15px; }
    .container{ padding:15px; }
    .modal-card{ padding:16px; }
    .modal-topo h3{ font-size:22px; }
    .rota-super-destaque{ font-size:26px; }
    .item-companheiro{
        flex-direction:column;
        align-items:flex-start;
    }
    .mesas-grid{
        grid-template-columns:repeat(auto-fit, minmax(110px, 110px));
        gap:12px;
    }
    .btn-mesa{
        width:110px;
        height:110px;
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

        <div id="resultadoMesa" class="resultado-mesa">
            <div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>
        </div>
    </div>
</div>

<script>
const DRIVERS = <?= json_encode($driversByDriverId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const modal = document.getElementById("modalMesa");
const tituloMesa = document.getElementById("tituloMesa");
const inputMesa = document.getElementById("inputMesa");
const resultadoMesa = document.getElementById("resultadoMesa");
const fecharModal = document.getElementById("fecharModal");
const btnPesquisarMesa = document.getElementById("btnPesquisarMesa");
const btnLimparMesa = document.getElementById("btnLimparMesa");
const botoesMesa = document.querySelectorAll(".btn-mesa");
const loadingOverlay = document.getElementById("loadingOverlay");

let mesaAtual = null;

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

function montarCompanheirosDaRota(motorista){
    const todosDaRota = Object.values(DRIVERS).filter(item =>
        (item.cluster_text || "") === (motorista.cluster_text || "")
    );

    if (todosDaRota.length <= 1) return "";

    let html = `
        <div class="bloco-companheiros">
            <h4>Motoristas da mesma rota</h4>
            <div class="lista-companheiros">
    `;

    todosDaRota.forEach(item => {
        const finalizado = (item.status || "").toLowerCase() === "finalizado";
        const classe = finalizado ? "finalizado" : "pendente";
        const luz = finalizado ? "apagada" : "acesa";
        const statusTexto = finalizado ? "Já carregou" : "Falta carregar";

        html += `
            <div class="item-companheiro ${classe}">
                <div class="esq">
                    <span class="luz ${luz}"></span>
                    <div>
                        <div class="nome-companheiro">${escaparHtml(item.driver_name)}</div>
                        <div>ID: ${escaparHtml(item.driver_id)} • ${escaparHtml(item.vehicle_type || "")}</div>
                    </div>
                </div>
                <div class="status-chip ${classe}">
                    ${statusTexto}
                </div>
            </div>
        `;
    });

    html += `
            </div>
        </div>
    `;

    return html;
}

async function renderResultadoMesa(idBuscado){
    const id = (idBuscado || "").trim();

    if (!id) {
        resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>';
        return;
    }

    const motorista = DRIVERS[id];

    if (!motorista) {
        resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista encontrado para o ID informado.</div>';
        return;
    }

    const clusters = Array.isArray(motorista.clusters) ? motorista.clusters : [];
    let htmlClusters = "";
    const ehMoto = (motorista.vehicle_type || "").toUpperCase() === "MOTO";

    if (ehMoto && clusters.length > 0) {
        htmlClusters += '<div class="moto-box">';
        htmlClusters += '<div class="moto-titulo">O QUE PASSAR PARA ESTE MOTORISTA</div>';
        htmlClusters += '<div class="moto-lista">';

        clusters.forEach(c => {
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

    const companheiros = montarCompanheirosDaRota(motorista);

    resultadoMesa.innerHTML = `
        <div class="titulo">Resultado encontrado</div>
        <div class="linha"><strong>ID:</strong> ${escaparHtml(motorista.driver_id)}</div>
        <div class="nome">${escaparHtml(motorista.driver_name)}</div>
        <div class="linha"><strong>Rota:</strong> ${escaparHtml(motorista.cluster_text)}</div>
        <div class="linha"><strong>Veículo:</strong> ${escaparHtml(motorista.vehicle_type)}</div>
        <div class="linha"><strong>Status:</strong> <span id="statusMesaAtual">${escaparHtml(motorista.status)}</span></div>

        ${totalPacotes}
        ${blocoRotaNormal}
        ${htmlClusters}
        ${companheiros}
        ${htmlConflito}
        ${htmlAcoes}
    `;
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
                await apiMesa("clear", { mesa: mesaAtual });
            } catch (e) {
                console.error("Erro ao limpar mesa após finalizar:", e.message);
            }

            const feedback = document.createElement("div");
            feedback.className = "feedback-status finalizado";
            feedback.textContent = "Rota finalizada com sucesso.";
            resultadoMesa.appendChild(feedback);
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

modal.addEventListener("click", function(e){
    if (e.target === modal) fecharMesa();
});

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
    resultadoMesa.classList.remove("destacado-conferindo");
    resultadoMesa.innerHTML = '<div class="msg-vazia">Nenhum motorista pesquisado nesta mesa.</div>';
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

document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && modal.classList.contains("ativo")) {
        fecharMesa();
    }
});

atualizarBotoesMesas();
</script>

</body>
</html>