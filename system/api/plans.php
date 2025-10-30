<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($method === 'GET') {
        $stmt = $conn->query("SELECT * FROM plans");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($plans);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        if (!isset($input['name']) || !isset($input['price'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome e preço são obrigatórios']);
            exit;
        }
        if (!is_numeric($input['price']) || $input['price'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Preço deve ser um número não negativo']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO plans (name, price) VALUES (:name, :price)");
        $stmt->execute(['name' => $input['name'], 'price' => $input['price']]);
        echo json_encode(['message' => 'Plano cadastrado com sucesso']);
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        if (!isset($_GET['id']) || !isset($input['name']) || !isset($input['price'])) {
            http_response_code(400);
            echo json_encode(['error' => 'ID, nome e preço são obrigatórios']);
            exit;
        }
        if (!is_numeric($input['price']) || $input['price'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Preço deve ser um número não negativo']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE plans SET name = :name, price = :price WHERE id = :id");
        $stmt->execute(['name' => $input['name'], 'price' => $input['price'], 'id' => $_GET['id']]);
        echo json_encode(['message' => 'Plano atualizado com sucesso']);
    } elseif ($method === 'DELETE') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'ID é obrigatório']);
            exit;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE planId = ?");
        $stmt->execute([$_GET['id']]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Não é possível excluir o plano pois ele está associado a clientes']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM plans WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(['message' => 'Plano excluído com sucesso']);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>