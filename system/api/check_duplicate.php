<?php
header('Content-Type: application/json');
require_once 'config.php';

try {
    $field = $_GET['field'] ?? '';
    $value = $_GET['value'] ?? '';

    if (!in_array($field, ['cpf', 'serial', 'pppoe']) || !$value) {
        http_response_code(400);
        echo json_encode(['error' => 'Campo ou valor inválido']);
        exit;
    }

    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE $field = ?");
    $stmt->execute([$value]);
    $count = $stmt->fetchColumn();

    echo json_encode(['exists' => $count > 0]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>