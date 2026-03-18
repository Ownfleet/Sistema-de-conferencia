<?php
echo "<pre>";
echo "DATABASE_URL: " . htmlspecialchars(getenv("DATABASE_URL") ?: "VAZIA") . "\n\n";

$parts = parse_url(getenv("DATABASE_URL") ?: "");
var_dump($parts);
echo "</pre>";