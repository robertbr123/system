<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuário e senha são obrigatórios']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$input['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($input['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        echo json_encode(['message' => 'Login bem-sucedido']);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário ou senha inválidos']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>