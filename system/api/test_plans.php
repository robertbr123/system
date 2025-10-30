<?php
require_once 'config.php';
$plans = $conn->query('SELECT id, name FROM plans')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
