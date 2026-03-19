<?php
require "db.php";


if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

$stmt = $pdo->query("
    SELECT
        mesa_numero,
        rota_texto,
        COUNT(*) AS total_registros,
        ROUND(AVG(duration_seconds)) AS media_segundos,
        MIN(duration_seconds) AS menor_tempo,
        MAX(duration_seconds) AS maior_tempo
    FROM mesa_tempos
    WHERE status = 'finalizado'
      AND duration_seconds IS NOT NULL
    GROUP BY mesa_numero, rota_texto
    ORDER BY mesa_numero, rota_texto
");
$rows = $stmt->fetchAll();

function fmt($seg){
    $seg = (int)$seg;
    $h = floor($seg / 3600);
    $m = floor(($seg % 3600) / 60);
    $s = $seg % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Relatório de Tempos</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;padding:30px}
.card{background:#fff;padding:20px;border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
table{width:100%;border-collapse:collapse}
th,td{padding:12px;border-bottom:1px solid #eee;text-align:left}
th{background:#f3f4f6}
a{display:inline-block;margin-bottom:20px}
</style>
</head>
<body>
<a href="admin.php">← Voltar ao admin</a>
<div class="card">
    <h2>Relatório de Tempos por Mesa e Rota</h2>
    <table>
        <tr>
            <th>Mesa</th>
            <th>Rota</th>
            <th>Registros</th>
            <th>Tempo Médio</th>
            <th>Menor Tempo</th>
            <th>Maior Tempo</th>
        </tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r["mesa_numero"]) ?></td>
            <td><?= htmlspecialchars($r["rota_texto"]) ?></td>
            <td><?= htmlspecialchars($r["total_registros"]) ?></td>
            <td><?= fmt($r["media_segundos"]) ?></td>
            <td><?= fmt($r["menor_tempo"]) ?></td>
            <td><?= fmt($r["maior_tempo"]) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>