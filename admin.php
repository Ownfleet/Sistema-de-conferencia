<?php
require "db.php";
session_start();

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

function filtroAtivo($valorAtual, $valorFiltro) {
    return $valorAtual === $valorFiltro ? "filtro-ativo" : "";
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
    --radius-sm:12px;
}

*{
    box-sizing:border-box;
}

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
    letter-spacing:.2px;
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
    max-width:1400px;
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

.card:hover{
    box-shadow:var(--shadow-md);
}

.card h3{
    margin:0 0 18px 0;
    font-size:20px;
    font-weight:800;
    color:var(--text);
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

.btn:active{
    transform:translateY(0);
}

.btn-brand{
    background:linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
}

.btn-brand:hover{
    box-shadow:0 10px 22px rgba(238,77,45,.24);
}

.btn-sec{
    background:linear-gradient(135deg, var(--gray) 0%, var(--gray-2) 100%);
}

.btn-sec:hover{
    box-shadow:0 10px 22px rgba(107,114,128,.22);
}

.btn-edit{
    background:linear-gradient(135deg, var(--blue) 0%, var(--blue-2) 100%);
}

.btn-edit:hover{
    box-shadow:0 10px 22px rgba(37,99,235,.24);
}

.btn-sair{
    background:linear-gradient(135deg, var(--dark) 0%, var(--dark-2) 100%);
}

.btn-sair:hover{
    box-shadow:0 10px 22px rgba(17,24,39,.28);
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
    border-radius:14px;
    background:#fff;
}

.file-custom{
    position:relative;
    display:inline-flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.file-custom input[type="file"]{
    max-width:320px;
    font-size:14px;
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

.kpis{
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

.kpi.total{
    background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
}

.kpi.ativo{
    background:linear-gradient(180deg,#ffffff 0%,#f3fff7 100%);
    border-color:#d9fbe5;
}

.kpi.conferindo{
    background:linear-gradient(180deg,#ffffff 0%,#fff9ef 100%);
    border-color:#fde7bf;
}

.kpi.finalizado{
    background:linear-gradient(180deg,#ffffff 0%,#f6f8fb 100%);
    border-color:#e5e7eb;
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
    transition:.15s ease;
}

.filtro-btn:hover{
    transform:translateY(-1px);
    box-shadow:var(--shadow-sm);
}

.filtro-ativo{
    background:linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
    color:#fff;
    border-color:transparent;
    box-shadow:0 10px 22px rgba(238,77,45,.20);
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
    min-width:920px;
}

thead th{
    position:sticky;
    top:0;
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
    vertical-align:middle;
}

tbody tr{
    background:#fff;
    transition:background .15s ease;
}

tbody tr:hover{
    background:#fafcff;
}

tbody tr:last-child td{
    border-bottom:none;
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
    box-shadow:0 0 10px rgba(34,197,94,.45);
}

.status-conferindo{
    color:#92400e;
    background:#fff7ed;
}

.status-conferindo::before{
    background:#f59e0b;
    box-shadow:0 0 10px rgba(245,158,11,.45);
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

@media (max-width: 980px){
    .container{
        padding:16px;
    }

    .topo{
        padding:18px 16px;
        flex-direction:column;
        align-items:flex-start;
    }

    .topo h2{
        font-size:22px;
    }

    .acoes-topo{
        width:100%;
    }

    .acoes-topo .btn{
        flex:1 1 auto;
    }

    .card{
        padding:18px;
    }

    .form-linha{
        align-items:flex-start;
    }

    .kpis{
        grid-template-columns:repeat(2, minmax(140px, 1fr));
    }
}

@media (max-width: 640px){
    .topo h2{
        font-size:20px;
    }

    .topo-sub{
        font-size:12px;
    }

    .btn{
        width:100%;
    }

    .form-linha{
        flex-direction:column;
        align-items:stretch;
    }

    .file-custom input[type="file"]{
        max-width:100%;
        width:100%;
    }

    .kpis{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<div class="topo">
    <div class="topo-esq">
        <h2>Painel Administrador</h2>
        <div class="topo-sub">Gerencie rotas, motoristas e status da operação</div>
    </div>

    <div class="acoes-topo">
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
            <div class="kpi total">
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?= $totalGeral ?></div>
            </div>

            <div class="kpi ativo">
                <div class="kpi-label">Ativos</div>
                <div class="kpi-value"><?= $totalAtivos ?></div>
            </div>

            <div class="kpi conferindo">
                <div class="kpi-label">Conferindo</div>
                <div class="kpi-value"><?= $totalConferindo ?></div>
            </div>

            <div class="kpi finalizado">
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
                <div class="file-custom">
                    <input type="file" name="arquivo" required>
                </div>
                <button class="btn btn-brand" type="submit">Importar Normal</button>
            </form>

            <form action="importar_moto.php" method="post" enctype="multipart/form-data" class="form-linha">
                <div class="file-custom">
                    <input type="file" name="arquivo" required>
                </div>
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

                                if ($status === "ativo") {
                                    $statusClass = "status-ativo";
                                } elseif ($status === "conferindo") {
                                    $statusClass = "status-conferindo";
                                }
                            ?>
                            <tr>
                                <td><span class="tag"><?= htmlspecialchars($d["driver_id"]) ?></span></td>
                                <td><strong><?= htmlspecialchars($d["driver_name"]) ?></strong></td>
                                <td><?= htmlspecialchars($d["cluster_text"]) ?></td>
                                <td><strong><?= htmlspecialchars($d["packages_total"]) ?></strong></td>
                                <td><?= htmlspecialchars($d["vehicle_type"]) ?></td>
                                <td>
                                    <span class="status <?= $statusClass ?>">
                                        <?= htmlspecialchars($d["status"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-edit" href="editar_motorista.php?id=<?= $d["id"] ?>">Editar</a>
                                </td>
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

</div>

</body>
</html>