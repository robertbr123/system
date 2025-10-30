<?php
header('Content-Type: application/json; charset=utf-8');
// Desabilitar buffer output para garantir que apenas JSON seja enviado
ob_start();

// Handler para erros de parsing
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro no servidor',
        'details' => "$errstr em $errfile linha $errline",
        'errno' => $errno
    ]);
    exit;
});

require_once 'config.php';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $action = $_GET['action'] ?? 'list';

    // Ações que não exigem autenticação
    if ($action === 'get_item_by_barcode') {
        $barcode = $_GET['barcode'] ?? '';
        if (empty($barcode)) {
            http_response_code(400);
            echo json_encode(['error' => 'Código de barras não fornecido']);
            exit;
        }
        $stmt = $conn->prepare("
            SELECT si.id, si.name, sib.barcode, si.quantity, si.min_quantity 
            FROM stock_items si
            JOIN stock_item_barcodes sib ON si.id = sib.item_id
            WHERE sib.barcode = ?
        ");
        $stmt->execute([$barcode]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Equipamento não encontrado para o código de barras']);
            exit;
        }
        echo json_encode($item);
        exit;
    } elseif ($action === 'get_client_by_cpf') {
        $cpf = $_GET['cpf'] ?? '';
        if (empty($cpf)) {
            http_response_code(400);
            echo json_encode(['error' => 'CPF não fornecido']);
            exit;
        }
        // Normalizar CPF (remover pontos e traços)
        $cpf = preg_replace('/[\.\-]/', '', $cpf);
        $stmt = $conn->prepare("SELECT cpf, name FROM clients WHERE cpf = ?");
        $stmt->execute([$cpf]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente não encontrado para o CPF']);
            exit;
        }
        echo json_encode($client);
        exit;
    } elseif ($action === 'add_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['item_id']) || !isset($input['installer']) || !isset($input['type']) || !isset($input['quantity']) || !isset($input['client_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros item_id, installer, type, quantity e client_id são obrigatórios']);
            exit;
        }
        // Normalizar CPF
        $client_id = preg_replace('/[\.\-]/', '', $input['client_id']);
        $stmt = $conn->prepare("SELECT cpf FROM clients WHERE cpf = ?");
        $stmt->execute([$client_id]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cliente não encontrado para o CPF informado']);
            exit;
        }
        $conn->beginTransaction();
        if ($input['type'] === 'entry') {
            $stmt = $conn->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$input['quantity'], $input['item_id']]);
        } elseif ($input['type'] === 'exit') {
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
        } else {
            $conn->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Tipo de movimentação inválido']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, installer, client_id, type, quantity) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$input['item_id'], $input['installer'], $client_id, $input['type'], $input['quantity']]);
        $conn->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    // Ações que exigem autenticação
    session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        error_log('Erro: Acesso não autenticado a stock.php');
        exit;
    }

    if ($action === 'check_auth') {
        echo json_encode(['success' => true, 'user_id' => $_SESSION['user_id']]);
        exit;
    } elseif ($action === 'list_items') {
        $stmt = $conn->query("
            SELECT si.id, si.name, GROUP_CONCAT(sib.barcode) as barcodes, si.quantity, si.min_quantity 
            FROM stock_items si
            LEFT JOIN stock_item_barcodes sib ON si.id = sib.item_id
            GROUP BY si.id
            ORDER BY si.name
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
        exit;
    } elseif ($action === 'get_client_by_serial') {
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
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['name']) || !isset($input['quantity']) || !isset($input['min_quantity']) || !isset($input['barcodes']) || !is_array($input['barcodes'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros name, quantity, min_quantity e barcodes (array) são obrigatórios']);
            exit;
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO stock_items (name, quantity, min_quantity) VALUES (?, ?, ?)");
        $stmt->execute([$input['name'], $input['quantity'], $input['min_quantity']]);
        $itemId = $conn->lastInsertId();
        $stmt = $conn->prepare("INSERT INTO stock_item_barcodes (item_id, barcode) VALUES (?, ?)");
        foreach ($input['barcodes'] as $barcode) {
            $barcode = trim($barcode);
            if (!empty($barcode)) {
                $stmt->execute([$itemId, $barcode]);
            }
        }
        $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, type, quantity) VALUES (?, 'entry', ?)");
        $stmt->execute([$itemId, $input['quantity']]);
        $conn->commit();
        echo json_encode(['success' => true, 'item_id' => $itemId]);
        exit;
    } elseif ($action === 'get_movements_by_installer') {
        $installer = $_GET['installer'] ?? '';
        $stmt = $conn->prepare("
            SELECT m.id, m.item_id, i.name as item_name, m.installer, m.client_id, c.name as client_name, m.type, m.quantity, m.movement_date
            FROM stock_movements m
            JOIN stock_items i ON m.item_id = i.id
            LEFT JOIN clients c ON m.client_id = c.cpf
            WHERE m.installer = ? OR ? = ''
            ORDER BY m.movement_date DESC
            LIMIT 100
        ");
        $stmt->execute([$installer, $installer]);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($movements);
        exit;
    } elseif ($action === 'list') {
        $stmt = $conn->query("
            SELECT si.id, si.name, GROUP_CONCAT(sib.barcode) as barcodes, si.quantity, si.min_quantity 
            FROM stock_items si
            LEFT JOIN stock_item_barcodes sib ON si.id = sib.item_id
            GROUP BY si.id
            ORDER BY si.name
        ");
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
        exit;
    } elseif ($action === 'edit_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['id']) || !isset($input['name']) || !isset($input['quantity']) || !isset($input['min_quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros id, name, quantity e min_quantity são obrigatórios']);
            exit;
        }
        
        // Validação
        if ($input['quantity'] < 0 || $input['min_quantity'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantidade e estoque mínimo não podem ser negativos']);
            exit;
        }
        
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("UPDATE stock_items SET name = ?, quantity = ?, min_quantity = ? WHERE id = ?");
            $stmt->execute([$input['name'], $input['quantity'], $input['min_quantity'], $input['id']]);
            
            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Item não encontrado']);
                exit;
            }
            
            // Atualizar códigos de barras se fornecidos
            if (isset($input['barcodes']) && is_array($input['barcodes'])) {
                $stmt = $conn->prepare("DELETE FROM stock_item_barcodes WHERE item_id = ?");
                $stmt->execute([$input['id']]);
                
                $stmt = $conn->prepare("INSERT INTO stock_item_barcodes (item_id, barcode) VALUES (?, ?)");
                foreach ($input['barcodes'] as $barcode) {
                    $barcode = trim($barcode);
                    if (!empty($barcode)) {
                        $stmt->execute([$input['id'], $barcode]);
                    }
                }
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item atualizado com sucesso']);
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    } elseif ($action === 'delete_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetro id é obrigatório']);
            exit;
        }
        
        $conn->beginTransaction();
        try {
            // Deletar códigos de barras
            $stmt = $conn->prepare("DELETE FROM stock_item_barcodes WHERE item_id = ?");
            $stmt->execute([$input['id']]);
            
            // Deletar movimentações
            $stmt = $conn->prepare("DELETE FROM stock_movements WHERE item_id = ?");
            $stmt->execute([$input['id']]);
            
            // Deletar item
            $stmt = $conn->prepare("DELETE FROM stock_items WHERE id = ?");
            $stmt->execute([$input['id']]);
            
            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Item não encontrado']);
                exit;
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item deletado com sucesso']);
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    } elseif ($action === 'get_movements_filtered') {
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        $type = $_GET['type'] ?? '';
        $installer = $_GET['installer'] ?? '';
        $client_id = $_GET['client_id'] ?? '';
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, (int)$_GET['limit']) : 50;
        $offset = ($page - 1) * $limit;
        
        $query = "
            SELECT m.id, m.item_id, i.name as item_name, m.installer, m.client_id, c.name as client_name, 
                   m.type, m.quantity, m.movement_date
            FROM stock_movements m
            JOIN stock_items i ON m.item_id = i.id
            LEFT JOIN clients c ON m.client_id = c.cpf
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($startDate)) {
            $query .= " AND DATE(m.movement_date) >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $query .= " AND DATE(m.movement_date) <= ?";
            $params[] = $endDate;
        }
        
        if (!empty($type) && in_array($type, ['entry', 'exit'])) {
            $query .= " AND m.type = ?";
            $params[] = $type;
        }
        
        if (!empty($installer)) {
            $query .= " AND m.installer LIKE ?";
            $params[] = "%$installer%";
        }
        
        if (!empty($client_id)) {
            $query .= " AND m.client_id LIKE ?";
            $params[] = "%$client_id%";
        }
        
        // Contar total de registros
        $countQuery = str_replace(
            ['SELECT m.id, m.item_id, i.name as item_name, m.installer, m.client_id, c.name as client_name, m.type, m.quantity, m.movement_date'],
            ['SELECT COUNT(*) as total'],
            $query
        );
        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute($params);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = isset($countResult['total']) ? $countResult['total'] : 0;
        
        // LIMIT e OFFSET não podem ser placeholders, então adicionamos diretamente na string
        $query .= " ORDER BY m.movement_date DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'movements' => $movements,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        exit;
    } elseif ($action === 'import_items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum arquivo fornecido']);
            exit;
        }
        
        $file = $_FILES['file'];
        
        // Validar upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo maior que o permitido pelo servidor',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o permitido pelo formulário',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
                UPLOAD_ERR_CANT_WRITE => 'Não foi possível escrever no disco',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
            ];
            echo json_encode(['error' => $uploadErrors[$file['error']] ?? 'Erro desconhecido no upload']);
            exit;
        }
        
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($fileExt, ['csv', 'xlsx', 'xls'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Apenas arquivos CSV, XLSX ou XLS são permitidos']);
            exit;
        }
        
        try {
            $rows = [];
            
            if ($fileExt === 'csv') {
                // Configurar locale para detectar encoding
                if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
                    throw new Exception('Arquivo temporário não legível');
                }
                
                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) {
                    throw new Exception('Não foi possível abrir o arquivo');
                }
                
                // Detectar encoding e limpar BOM se presente
                $firstLine = fgets($handle);
                rewind($handle);
                
                // Remover BOM UTF-8 se presente
                if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
                    fseek($handle, 3);
                }
                
                $header = null;
                $lineNumber = 0;
                
                while (($row = fgetcsv($handle)) !== false) {
                    $lineNumber++;
                    
                    // Ignorar linhas vazias
                    if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                        continue;
                    }
                    
                    if ($header === null) {
                        $header = $row;
                        // Trim dos headers
                        $header = array_map('trim', $header);
                        // Converter para lowercase para compatibilidade
                        $header = array_map('strtolower', $header);
                        continue;
                    }
                    
                    // Combinar header com row, trimando valores
                    $rowData = [];
                    foreach ($header as $index => $colName) {
                        $rowData[$colName] = isset($row[$index]) ? trim($row[$index]) : '';
                    }
                    
                    $rows[] = $rowData;
                }
                
                if (!fclose($handle)) {
                    throw new Exception('Erro ao fechar arquivo');
                }
            } else {
                // Para XLSX/XLS, seria necessário usar uma biblioteca como PhpSpreadsheet
                // Por enquanto, retornar erro
                http_response_code(400);
                echo json_encode(['error' => 'Para XLSX/XLS, instale PhpSpreadsheet. Use CSV por enquanto.']);
                exit;
            }
            
            if (empty($rows)) {
                http_response_code(400);
                echo json_encode(['error' => 'Arquivo vazio ou inválido']);
                exit;
            }
            
            $conn->beginTransaction();
            $imported = 0;
            $errors = [];
            
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2; // +2 porque começa em 0 e a primeira linha é header
                
                // Normalizar nomes das colunas
                $nome = $row['nome'] ?? $row['name'] ?? '';
                $codigos = $row['codigos_barras'] ?? $row['códigos_barras'] ?? $row['barcodes'] ?? '';
                $quantidade = $row['quantidade'] ?? $row['quantity'] ?? '';
                $minimo = $row['estoque_minimo'] ?? $row['estoque mínimo'] ?? $row['min_quantity'] ?? '';
                
                // Validar campos obrigatórios
                if (empty($nome)) {
                    $errors[] = "Linha $rowNum: Campo 'nome' é obrigatório";
                    continue;
                }
                if (empty($codigos)) {
                    $errors[] = "Linha $rowNum: Campo 'codigos_barras' é obrigatório";
                    continue;
                }
                if ($quantidade === '') {
                    $errors[] = "Linha $rowNum: Campo 'quantidade' é obrigatório";
                    continue;
                }
                if ($minimo === '') {
                    $errors[] = "Linha $rowNum: Campo 'estoque_minimo' é obrigatório";
                    continue;
                }
                
                $name = trim($nome);
                
                // Dividir códigos de barras e filtrar vazios
                $barcodes = array_filter(array_map('trim', explode(',', $codigos)));
                
                // Converter e validar números
                if (!is_numeric($quantidade) || !is_numeric($minimo)) {
                    $errors[] = "Linha $rowNum: Quantidade e estoque mínimo devem ser números";
                    continue;
                }
                
                $quantity = (int)$quantidade;
                $minQuantity = (int)$minimo;
                
                // Validação
                if ($quantity < 0 || $minQuantity < 0) {
                    $errors[] = "Linha $rowNum: Quantidade e estoque mínimo não podem ser negativos";
                    continue;
                }
                
                if (empty($barcodes)) {
                    $errors[] = "Linha $rowNum: Pelo menos um código de barras válido é obrigatório";
                    continue;
                }
                
                try {
                    // Inserir item
                    $stmt = $conn->prepare("INSERT INTO stock_items (name, quantity, min_quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $quantity, $minQuantity]);
                    $itemId = $conn->lastInsertId();
                    
                    // Inserir códigos de barras
                    $stmt = $conn->prepare("INSERT INTO stock_item_barcodes (item_id, barcode) VALUES (?, ?)");
                    foreach ($barcodes as $barcode) {
                        $stmt->execute([$itemId, $barcode]);
                    }
                    
                    // Registrar movimento inicial
                    $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, type, quantity) VALUES (?, 'entry', ?)");
                    $stmt->execute([$itemId, $quantity]);
                    
                    $imported++;
                } catch (Exception $e) {
                    $errors[] = "Linha $rowNum: " . $e->getMessage();
                }
            }
            
            $conn->commit();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'imported' => $imported,
                'total' => count($rows),
                'errors' => $errors,
                'message' => "$imported de " . count($rows) . " itens importados com sucesso"
            ]);
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            http_response_code(400);
            ob_end_clean();
            echo json_encode(['error' => 'Erro ao processar arquivo: ' . $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida']);
} catch (PDOException $e) {
    http_response_code(500);
    $errorMessage = 'Erro no banco de dados: ' . $e->getMessage();
    error_log($errorMessage);
    ob_end_clean();
    echo json_encode(['error' => $errorMessage]);
} catch (Exception $e) {
    http_response_code(500);
    $errorMessage = 'Erro inesperado: ' . $e->getMessage();
    error_log($errorMessage);
    ob_end_clean();
    echo json_encode(['error' => $errorMessage]);
}

ob_end_flush();
?>
