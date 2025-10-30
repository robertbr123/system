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
        $stmt = $conn->query("
            SELECT i.*, COUNT(c.cpf) AS installation_count
            FROM installers i
            LEFT JOIN clients c ON c.installer = i.name
            GROUP BY i.id, i.name
        ");
        $installers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($installers);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        if (!isset($input['name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome é obrigatório']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO installers (name) VALUES (:name)");
        $stmt->execute(['name' => $input['name']]);
        echo json_encode(['message' => 'Instalador cadastrado com sucesso']);
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        if (!isset($_GET['id']) || !isset($input['name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'ID e nome são obrigatórios']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE installers SET name = :name WHERE id = :id");
        $stmt->execute(['name' => $input['name'], 'id' => $_GET['id']]);
        echo json_encode(['message' => 'Instalador atualizado com sucesso']);
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
        $stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE installer = (SELECT name FROM installers WHERE id = ?)");
        $stmt->execute([$_GET['id']]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Não é possível excluir o instalador pois ele está associado a clientes']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM installers WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(['message' => 'Instalador excluído com sucesso']);
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