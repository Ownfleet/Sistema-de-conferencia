<?php
require "db.php";


if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["user"]["role"] !== "admin") {
    die("Acesso negado");
}

$id = $_GET["id"] ?? $_POST["id"] ?? null;

if (!$id) {
    die("ID não informado.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $driver_id = trim($_POST["driver_id"] ?? "");
    $driver_name = trim($_POST["driver_name"] ?? "");
    $cluster_text = trim($_POST["cluster_text"] ?? "");
    $packages_total = (int)($_POST["packages_total"] ?? 0);
    $vehicle_type = trim($_POST["vehicle_type"] ?? "");
    $status = trim($_POST["status"] ?? "ativo");

    $stmt = $pdo->prepare("
        UPDATE drivers
        SET driver_id = ?, driver_name = ?, cluster_text = ?, packages_total = ?, vehicle_type = ?, status = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $driver_id,
        $driver_name,
        $cluster_text,
        $packages_total,
        $vehicle_type,
        $status,
        $id
    ]);

    header("Location: admin.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->execute([$id]);
$driver = $stmt->fetch();

if (!$driver) {
    die("Motorista não encontrado.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editar Motorista</title>
<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#f4f6fb;
    padding:30px;
}
.card{
    max-width:700px;
    margin:0 auto;
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 4px 14px rgba(0,0,0,0.06);
}
h2{
    margin-top:0;
}
label{
    display:block;
    margin-top:14px;
    margin-bottom:6px;
    font-weight:bold;
}
input, select{
    width:100%;
    padding:12px;
    border:1px solid #ddd;
    border-radius:8px;
    box-sizing:border-box;
}
.btn{
    margin-top:18px;
    background:#ee4d2d;
    color:#fff;
    padding:12px 16px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
}
.btn-voltar{
    background:#6b7280;
    margin-left:10px;
}
</style>
</head>
<body>

<div class="card">
    <h2>Editar Motorista</h2>

    <form method="post">
        <input type="hidden" name="id" value="<?= $driver["id"] ?>">

        <label>ID</label>
        <input type="text" name="driver_id" value="<?= htmlspecialchars($driver["driver_id"]) ?>" required>

        <label>Nome</label>
        <input type="text" name="driver_name" value="<?= htmlspecialchars($driver["driver_name"]) ?>" required>

        <label>Cluster</label>
        <input type="text" name="cluster_text" value="<?= htmlspecialchars($driver["cluster_text"]) ?>" required>

        <label>Pacotes</label>
        <input type="number" name="packages_total" value="<?= htmlspecialchars($driver["packages_total"]) ?>" required>

        <label>Veículo</label>
        <input type="text" name="vehicle_type" value="<?= htmlspecialchars($driver["vehicle_type"]) ?>" required>

        <label>Status</label>
        <select name="status">
            <option value="ativo" <?= $driver["status"] === "ativo" ? "selected" : "" ?>>Ativo</option>
            <option value="conferindo" <?= $driver["status"] === "conferindo" ? "selected" : "" ?>>Conferindo</option>
            <option value="finalizado" <?= $driver["status"] === "finalizado" ? "selected" : "" ?>>Finalizado</option>
        </select>

        <button type="submit" class="btn">Salvar Alterações</button>
        <a href="admin.php" class="btn btn-voltar">Voltar</a>
    </form>
</div>

</body>
</html>