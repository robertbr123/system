<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';

$userId = (int)$_SESSION['user_id'];

function ensure_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS mt_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        host VARCHAR(255) NOT NULL,
        port INT NOT NULL DEFAULT 8728,
        tls TINYINT(1) NOT NULL DEFAULT 0,
        username VARCHAR(255) NOT NULL,
        password_enc TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_device (user_id, host, port, tls, username),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $conn->exec($sql);
}

function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    return $data;
}

try {
    ensure_table($conn);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    if ($method === 'POST') {
        $input = json_input();
        if (!$action && isset($input['action'])) $action = $input['action'];
    } else {
        $input = [];
    }

    switch ($action) {
        case 'list':
            $stmt = $conn->prepare('SELECT id, name, host, port, tls, username FROM mt_devices WHERE user_id = :uid ORDER BY created_at DESC');
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        case 'save':
            $name = trim($input['name'] ?? '');
            $host = trim($input['host'] ?? '');
            $port = (int)($input['port'] ?? 8728);
            $tls  = !empty($input['tls']) ? 1 : 0;
            $username = trim($input['username'] ?? '');
            $password = (string)($input['password'] ?? '');
            $savePassword = !empty($input['savePassword']);
            if ($name === '' || $host === '' || $username === '') {
                throw new Exception('Campos obrigatórios: name, host, username');
            }
            $password_enc = $savePassword && $password !== '' ? encrypt_secret($password) : null;
            // upsert (insert or update name/password_enc)
            $sql = 'INSERT INTO mt_devices (user_id, name, host, port, tls, username, password_enc) VALUES (:uid,:name,:host,:port,:tls,:username,:pwd)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), password_enc = VALUES(password_enc)';
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':name' => $name,
                ':host' => $host,
                ':port' => $port,
                ':tls' => $tls,
                ':username' => $username,
                ':pwd' => $password_enc
            ]);
            echo json_encode(['success' => true]);
            break;
        case 'get':
            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) throw new Exception('Id inválido');
            $stmt = $conn->prepare('SELECT id, name, host, port, tls, username, password_enc FROM mt_devices WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Equipamento não encontrado');
            $row['password'] = $row['password_enc'] ? decrypt_secret($row['password_enc']) : '';
            unset($row['password_enc']);
            echo json_encode(['success' => true, 'data' => $row]);
            break;
        case 'delete':
            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) throw new Exception('Id inválido');
            $stmt = $conn->prepare('DELETE FROM mt_devices WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
