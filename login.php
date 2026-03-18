<?php
require "db.php";


$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $senha = $_POST["senha"] ?? "";

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND active = true LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($senha, $user["password_hash"])) {
        $_SESSION["user"] = $user;

        if ($user["role"] === "admin") {
            header("Location: admin.php");
        } else {
            header("Location: conferente.php");
        }
        exit;
    } else {
        $erro = "E-mail ou senha inválidos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Login</title>
<style>
body{
  margin:0;
  font-family:Arial,sans-serif;
  background:#f4f6fb;
  display:flex;
  align-items:center;
  justify-content:center;
  height:100vh;
}
.box{
  width:360px;
  background:#fff;
  padding:30px;
  border-radius:18px;
  box-shadow:0 10px 30px rgba(0,0,0,.08);
}
input,button{
  width:100%;
  padding:12px;
  margin-top:10px;
  border-radius:10px;
  border:1px solid #ddd;
  box-sizing:border-box;
}
button{
  background:#ee4d2d;
  color:#fff;
  border:0;
  font-weight:bold;
  cursor:pointer;
}
.erro{
  color:#b91c1c;
  margin-top:10px;
}
</style>
</head>
<body>
<div class="box">
  <h2>Entrar</h2>
  <form method="post">
    <input type="email" name="email" placeholder="E-mail" required>
    <input type="password" name="senha" placeholder="Senha" required>
    <button type="submit">Entrar</button>
  </form>

  <?php if ($erro): ?>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>
</div>
</body>
</html>