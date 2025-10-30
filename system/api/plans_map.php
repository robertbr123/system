<?php
require_once 'config.php';
$plans = $conn->query('SELECT id, name FROM plans')->fetchAll(PDO::FETCH_ASSOC);
$planMap = [];
foreach ($plans as $p) {
    $planMap[strtolower($p['name'])] = $p['id'];
}
echo json_encode(['plans' => $plans, 'map' => $planMap], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
