<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    error_log('Erro: Acesso não autenticado a stock.php');
    exit;
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $action = $_GET['action'] ?? 'list';

    if ($action === 'list_items') {
        // Listar itens de estoque
        $stmt = $conn->query("SELECT id, name, barcode, quantity, min_quantity FROM stock_items ORDER BY name");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
        exit;
    } elseif ($action === 'get_item_by_barcode') {
        // Buscar item por código de barras
        $barcode = $_GET['barcode'] ?? '';
        if (empty($barcode)) {
            http_response_code(400);
            echo json_encode(['error' => 'Código de barras não fornecido']);
            exit;
        }
        $stmt = $conn->prepare("SELECT id, name, barcode, quantity, min_quantity FROM stock_items WHERE barcode = ?");
        $stmt->execute([$barcode]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item não encontrado para o código de barras']);
            exit;
        }
        echo json_encode($item);
        exit;
    } elseif ($action === 'get_client_by_serial') {
        // Buscar cliente por serial
        $serial = $_GET['serial'] ?? '';
        if (empty($serial)) {
            http_response_code(400);
            echo json_encode(['error' => 'Serial não fornecido']);
            exit;
        }
        $stmt = $conn->prepare("SELECT cpf, name, serial FROM clients WHERE serial = ?");
        $stmt->execute([$serial]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente não encontrado para o serial']);
            exit;
        }
        echo json_encode($client);
        exit;
    } elseif ($action === 'add_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Adicionar novo item
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['name']) || !isset($input['quantity']) || !isset($input['min_quantity']) || !isset($input['barcode'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros name, quantity, min_quantity e barcode são obrigatórios']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO stock_items (name, barcode, quantity, min_quantity) VALUES (?, ?, ?, ?)");
        $stmt->execute([$input['name'], $input['barcode'], $input['quantity'], $input['min_quantity']]);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'add_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Registrar movimentação
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['item_id']) || !isset($input['installer']) || !isset($input['type']) || !isset($input['quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros item_id, installer, type e quantity são obrigatórios']);
            exit;
        }
        $conn->beginTransaction();
        // Atualizar quantidade no estoque
        if ($input['type'] === 'entry') {
            $stmt = $conn->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$input['quantity'], $input['item_id']]);
        } else {
            $stmt = $conn->prepare("SELECT quantity FROM stock_items WHERE id = ?");
            $stmt->execute([$input['item_id']]);
            $currentQuantity = $stmt->fetch(PDO::FETCH_ASSOC)['quantity'];
            if ($currentQuantity < $input['quantity']) {
                $conn->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Quantidade insuficiente no estoque']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE stock_items SET quantity = quantity - ? WHERE id = ?");
            $stmt->execute([$input['quantity'], $input['item_id']]);
        }
        // Registrar movimentação com client_id (cpf)
        $clientId = isset($input['client_id']) && !empty($input['client_id']) ? $input['client_id'] : null;
        $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, installer, client_id, type, quantity) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$input['item_id'], $input['installer'], $clientId, $input['type'], $input['quantity']]);
        $conn->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    // Listar estoque e movimentações
    $stmt = $conn->query("SELECT id, name, barcode, quantity, min_quantity FROM stock_items ORDER BY name");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->query("
        SELECT m.id, m.item_id, i.name as item_name, m.installer, m.client_id, c.name as client_name, m.type, m.quantity, m.movement_date
        FROM stock_movements m
        JOIN stock_items i ON m.item_id = i.id
        LEFT JOIN clients c ON m.client_id = c.cpf
        ORDER BY m.movement_date DESC
        LIMIT 100
    ");
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->query("
        SELECT installer, COUNT(*) as count
        FROM stock_movements
        GROUP BY installer
        ORDER BY count DESC
        LIMIT 5
    ");
    $movementsByInstaller = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'items' => $items,
        'movements' => $movements,
        'movementsByInstaller' => $movementsByInstaller
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    $errorMessage = 'Erro no banco de dados: ' . $e->getMessage();
    error_log($errorMessage);
    echo json_encode(['error' => $errorMessage]);
} catch (Exception $e) {
    http_response_code(500);
    $errorMessage = 'Erro inesperado: ' . $e->getMessage();
    error_log($errorMessage);
    echo json_encode(['error' => $errorMessage]);
}
?>