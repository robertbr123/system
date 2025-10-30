<?php
require_once 'config.php';
$users = $conn->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
?>
