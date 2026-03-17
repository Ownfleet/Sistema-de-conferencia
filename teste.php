<?php

require "db.php";

$stmt = $pdo->query("SELECT now() AS agora");
$row = $stmt->fetch();

echo "Conectado com sucesso<br>";
echo "Hora do banco: " . $row["agora"];