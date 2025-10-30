<?php
header('Content-Type: application/json; charset=utf-8');

// Ativar exibição de erros para depuração
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Iniciar sessão
session_start();

// Função para enviar resposta de erro
function sendError($status, $error, $details = '') {
    http_response_code($status);
    $response = ['success' => false, 'error' => $error];
    if ($details) {
        $response['details'] = $details;
    }
    error_log("Erro em client_history.php: $error - $details");
    echo json_encode($response);
    exit;
}

// Verificar inclusão de config.php
if (!file_exists('config.php')) {
    sendError(500, 'Arquivo de configuração não encontrado', 'config.php ausente');
}
require_once 'config.php';

try {
    // Verificar conexão com o banco
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Criar tabela de histórico se não existir
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS client_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_cpf VARCHAR(14) NOT NULL,
            client_name VARCHAR(255) NOT NULL,
            action_type ENUM('CREATE', 'UPDATE', 'DELETE', 'LOGIN', 'VIEW') NOT NULL,
            action_description TEXT,
            old_data JSON NULL,
            new_data JSON NULL,
            user_id INT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_cpf (client_cpf),
            INDEX idx_action_type (action_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $conn->exec($createTableSQL);

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_auth') {
        // VERIFICAR AUTENTICAÇÃO
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        echo json_encode(['success' => true, 'user_id' => $_SESSION['user_id']]);
        exit;
    } elseif ($method === 'GET') {
        // LISTAR HISTÓRICO
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }

        $clientCpf = $_GET['client_cpf'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100); // Máximo 100 registros
        $offset = (int)($_GET['offset'] ?? 0);

        $sql = "
            SELECT 
                id,
                client_cpf,
                client_name,
                action_type,
                action_description,
                old_data,
                new_data,
                user_id,
                ip_address,
                created_at
            FROM client_history 
        ";
        
        $params = [];
        if ($clientCpf) {
            $sql .= " WHERE client_cpf = ?";
            $params[] = $clientCpf;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decodificar JSON
        foreach ($history as &$record) {
            if ($record['old_data']) {
                $record['old_data'] = json_decode($record['old_data'], true);
            }
            if ($record['new_data']) {
                $record['new_data'] = json_decode($record['new_data'], true);
            }
        }

        echo json_encode(['success' => true, 'data' => $history]);

    } elseif ($method === 'POST') {
        // ADICIONAR ENTRADA NO HISTÓRICO
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }

        $clientCpf = $input['client_cpf'] ?? null;
        $clientName = $input['client_name'] ?? null;
        $actionType = $input['action_type'] ?? null;
        $actionDescription = $input['action_description'] ?? null;
        $oldData = $input['old_data'] ?? null;
        $newData = $input['new_data'] ?? null;

        if (!$clientCpf || !$clientName || !$actionType) {
            sendError(400, 'Dados obrigatórios não fornecidos', 'client_cpf, client_name e action_type são obrigatórios');
        }

        $validActions = ['CREATE', 'UPDATE', 'DELETE', 'LOGIN', 'VIEW'];
        if (!in_array($actionType, $validActions)) {
            sendError(400, 'Tipo de ação inválido', 'action_type deve ser: ' . implode(', ', $validActions));
        }

        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $stmt = $conn->prepare("
            INSERT INTO client_history 
            (client_cpf, client_name, action_type, action_description, old_data, new_data, user_id, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $clientCpf,
            $clientName,
            $actionType,
            $actionDescription,
            $oldData ? json_encode($oldData) : null,
            $newData ? json_encode($newData) : null,
            $_SESSION['user_id'],
            $ipAddress,
            $userAgent
        ]);

        echo json_encode(['success' => true, 'message' => 'Ação registrada no histórico']);
    } else {
        sendError(405, 'Método não permitido', "Método $method não é suportado");
    }

} catch (PDOException $e) {
    error_log("Erro de banco de dados em client_history.php: " . $e->getMessage());
    sendError(500, 'Erro interno do servidor', 'Falha na conexão com o banco de dados');
} catch (Exception $e) {
    error_log("Erro geral em client_history.php: " . $e->getMessage());
    sendError(500, 'Erro interno do servidor', $e->getMessage());
}
?>