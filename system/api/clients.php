<?php
header('Content-Type: application/json; charset=utf-8');

// Ativar exibição de erros para depuração (remover em produção)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Iniciar sessão
session_start();

// Inicializar Redis (opcional, com fallback)
$redis = null;
if (extension_loaded('redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 2);
        $redis->ping();
    } catch (Exception $e) {
        // Redis não disponível, continuar sem cache
        $redis = null;
        error_log("Redis não disponível: " . $e->getMessage());
    }
} else {
    // Redis extension não instalada
    $redis = null;
}

// Função para enviar resposta de erro
function sendError($status, $error, $details = '') {
    http_response_code($status);
    $response = ['success' => false, 'error' => $error];
    if ($details) {
        $response['details'] = $details;
    }
    error_log("Erro em clients.php: $error - $details");
    echo json_encode($response);
    exit;
}

// Verificar inclusão de config.php
if (!file_exists('config.php')) {
    sendError(500, 'Arquivo de configuração não encontrado', 'config.php ausente');
}
require_once 'config.php';

// Função para validar CPF
function validateCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

try {
    // Verificar conexão com o banco
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log('Conexão com o banco de dados estabelecida com sucesso');

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
        // LISTAR CLIENTES - EXIGE LOGIN
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        
        // Verificar se é solicitação de clientes recentes
        if (isset($_GET['recent'])) {
            $limit = intval($_GET['recent']);
            if ($limit <= 0 || $limit > 50) $limit = 5;
            
            $stmt = $conn->prepare("
                SELECT c.*, p.name AS plan_name
                FROM clients c
                LEFT JOIN plans p ON c.planId = p.id
                ORDER BY c.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $clients]);
            exit;
        }
        
        // Tentar pegar do cache
        $cacheKey = 'clients_list';
        if ($redis) {
            $cachedData = $redis->get($cacheKey);
            if ($cachedData) {
                error_log("Cache HIT para clientes");
                echo $cachedData;
                exit;
            }
        }
        
        error_log("Cache MISS para clientes - executando query");
        $stmt = $conn->prepare("
            SELECT c.*, p.name AS plan_name,
                   si.name AS equipment_name
            FROM clients c
            LEFT JOIN plans p ON c.planId = p.id
            LEFT JOIN (
                SELECT sm.client_id, sm.item_id, si.name as equipment_name,
                       ROW_NUMBER() OVER (PARTITION BY sm.client_id ORDER BY sm.id DESC) as rn
                FROM stock_movements sm
                LEFT JOIN stock_items si ON sm.item_id = si.id
                WHERE sm.type = 'exit'
            ) sm ON c.cpf = sm.client_id AND sm.rn = 1
            LEFT JOIN stock_items si ON sm.item_id = si.id
            ORDER BY c.name
        ");
        $stmt->execute();
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response = json_encode(['success' => true, 'data' => $clients]);
        
        // Guardar no cache por 1 hora (3600 segundos)
        if ($redis) {
            $redis->setex($cacheKey, 3600, $response);
        }
        
        echo $response;
    } elseif ($method === 'POST') {
        // CADASTRAR CLIENTE - SEM LOGIN
        if (!isset($input['cpf']) || !isset($input['serial']) || !isset($input['name']) || !isset($input['address']) || !isset($input['planId']) || !isset($input['installer']) || !isset($input['pppoe']) || !isset($input['password'])) {
            sendError(400, 'Todos os campos obrigatórios (CPF, Serial, Nome, Endereço, Plano, Instalador, PPPoE, Senha) devem ser preenchidos');
        }
        if (!validateCPF($input['cpf'])) {
            sendError(400, 'CPF inválido');
        }
        
        // Verificar CPF duplicado
        $cpfClean = preg_replace('/[^0-9]/', '', $input['cpf']);
        $checkCPF = $conn->prepare("SELECT COUNT(*) FROM clients WHERE cpf = ?");
        $checkCPF->execute([$cpfClean]);
        if ($checkCPF->fetchColumn() > 0) {
            sendError(409, 'CPF já cadastrado no sistema');
        }
        
        // Verificar Serial único
        $checkSerial = $conn->prepare("SELECT COUNT(*) FROM clients WHERE serial = ?");
        $checkSerial->execute([$input['serial']]);
        if ($checkSerial->fetchColumn() > 0) {
            sendError(409, 'Serial já está em uso por outro cliente');
        }
        
        if ($input['phone'] && strlen(preg_replace('/[^0-9]/', '', $input['phone'])) < 10) {
            sendError(400, 'Telefone deve conter pelo menos 10 dígitos');
        }
        if ($input['dueDay'] && !in_array($input['dueDay'], [10, 20, 30])) {
            sendError(400, 'Dia de vencimento deve ser 10, 20 ou 30');
        }
        if ($input['city'] && !in_array($input['city'], ['Ipixuna', 'Eirunepé', 'Carauari', 'Itamarati'])) {
            sendError(400, 'Cidade inválida');
        }
        $stmt = $conn->prepare("
            INSERT INTO clients (cpf, serial, name, address, number, complement, city, dueDay, phone, birthDate, observation, planId, installer, pppoe, password, active)
            VALUES (:cpf, :serial, :name, :address, :number, :complement, :city, :dueDay, :phone, :birthDate, :observation, :planId, :installer, :pppoe, :password, :active)
        ");
        $stmt->execute([
            'cpf' => $cpfClean,
            'serial' => $input['serial'],
            'name' => $input['name'],
            'address' => $input['address'],
            'number' => $input['number'] ?? null,
            'complement' => $input['complement'] ?? null,
            'city' => $input['city'] ?? null,
            'dueDay' => $input['dueDay'] ?? null,
            'phone' => $input['phone'] ? preg_replace('/[^0-9]/', '', $input['phone']) : null,
            'birthDate' => $input['birthDate'] ?: null,
            'observation' => $input['observation'] ?? null,
            'planId' => $input['planId'],
            'installer' => $input['installer'],
            'pppoe' => $input['pppoe'],
            'password' => $input['password'],
            'active' => isset($input['active']) ? (int)$input['active'] : 1
        ]);
        echo json_encode(['success' => true, 'message' => 'Cliente cadastrado com sucesso']);
        
        // Invalidar cache
        if ($redis) {
            $redis->del('clients_list');
            error_log("Cache invalidado para clientes (POST)");
        }
    } elseif ($method === 'PUT') {
        // EDITAR CLIENTE - EXIGE LOGIN
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        if (!isset($_GET['cpf']) || !isset($input['serial']) || !isset($input['name']) || !isset($input['address']) || !isset($input['planId']) || !isset($input['installer']) || !isset($input['pppoe']) || !isset($input['password'])) {
            sendError(400, 'Todos os campos obrigatórios (CPF, Serial, Nome, Endereço, Plano, Instalador, PPPoE, Senha) devem ser preenchidos');
        }
        
        $cpfClean = preg_replace('/[^0-9]/', '', $_GET['cpf']);
        
        // Verificar se cliente existe
        $checkClient = $conn->prepare("SELECT COUNT(*) FROM clients WHERE cpf = ?");
        $checkClient->execute([$cpfClean]);
        if ($checkClient->fetchColumn() === 0) {
            sendError(404, 'Cliente não encontrado para edição');
        }
        
        // Verificar Serial único (excluindo o cliente atual)
        $checkSerial = $conn->prepare("SELECT COUNT(*) FROM clients WHERE serial = ? AND cpf != ?");
        $checkSerial->execute([$input['serial'], $cpfClean]);
        if ($checkSerial->fetchColumn() > 0) {
            sendError(409, 'Serial já está em uso por outro cliente');
        }
        
        if ($input['phone'] && strlen(preg_replace('/[^0-9]/', '', $input['phone'])) < 10) {
            sendError(400, 'Telefone deve conter pelo menos 10 dígitos');
        }
        if ($input['dueDay'] && !in_array($input['dueDay'], [10, 20, 30])) {
            sendError(400, 'Dia de vencimento deve ser 10, 20 ou 30');
        }
        if ($input['city'] && !in_array($input['city'], ['Ipixuna', 'Eirunepé', 'Carauari', 'Itamarati'])) {
            sendError(400, 'Cidade inválida');
        }
        $stmt = $conn->prepare("
            UPDATE clients
            SET serial = :serial, name = :name, address = :address, number = :number, complement = :complement,
                city = :city, dueDay = :dueDay, phone = :phone, birthDate = :birthDate, observation = :observation,
                planId = :planId, installer = :installer, pppoe = :pppoe, password = :password, active = :active
            WHERE cpf = :cpf
        ");
        $stmt->execute([
            'cpf' => $cpfClean,
            'serial' => $input['serial'],
            'name' => $input['name'],
            'address' => $input['address'],
            'number' => $input['number'] ?? null,
            'complement' => $input['complement'] ?? null,
            'city' => $input['city'] ?? null,
            'dueDay' => $input['dueDay'] ?? null,
            'phone' => $input['phone'] ? preg_replace('/[^0-9]/', '', $input['phone']) : null,
            'birthDate' => $input['birthDate'] ?: null,
            'observation' => $input['observation'] ?? null,
            'planId' => $input['planId'],
            'installer' => $input['installer'],
            'pppoe' => $input['pppoe'],
            'password' => $input['password'],
            'active' => isset($input['active']) ? (int)$input['active'] : 1
        ]);
        echo json_encode(['success' => true, 'message' => 'Cliente atualizado com sucesso']);
        
        // Invalidar cache
        if ($redis) {
            $redis->del('clients_list');
            error_log("Cache invalidado para clientes (PUT)");
        }
    } elseif ($method === 'PATCH' && isset($_GET['action']) && $_GET['action'] === 'update_active') {
        // ATUALIZAR STATUS ATIVO - EXIGE LOGIN
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        if (!isset($input['cpf']) || !isset($input['active'])) {
            sendError(400, 'CPF e active são obrigatórios');
        }
        $stmt = $conn->prepare("UPDATE clients SET active = ? WHERE cpf = ?");
        $stmt->execute([(int)$input['active'], preg_replace('/[^0-9]/', '', $input['cpf'])]);
        echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso']);
        
        // Invalidar cache
        if ($redis) {
            $redis->del('clients_list');
            error_log("Cache invalidado para clientes (PATCH)");
        }
    } elseif ($method === 'DELETE') {
        // EXCLUIR CLIENTE - EXIGE LOGIN
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        if (!isset($_GET['cpf'])) {
            sendError(400, 'CPF é obrigatório');
        }
        $stmt = $conn->prepare("DELETE FROM clients WHERE cpf = ?");
        $stmt->execute([preg_replace('/[^0-9]/', '', $_GET['cpf'])]);
        echo json_encode(['success' => true, 'message' => 'Cliente excluído com sucesso']);
        
        // Invalidar cache
        if ($redis) {
            $redis->del('clients_list');
            error_log("Cache invalidado para clientes (DELETE)");
        }
    } elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'import_csv') {
        // IMPORTAR CLIENTES VIA CSV - EXIGE LOGIN
        if (!isset($_SESSION['user_id'])) {
            sendError(401, 'Não autenticado', 'Sessão não encontrada');
        }
        if (!isset($input['clients']) || !is_array($input['clients'])) {
            sendError(400, 'Dados de clientes não fornecidos');
        }

        $clients = $input['clients'];
        if (count($clients) === 0) {
            sendError(400, 'Nenhum cliente para importar');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($clients as $index => $client) {
            try {
                // Validar campos obrigatórios
                $required = ['nome', 'cpf', 'serial', 'endereço', 'plano', 'instalador', 'pppoe', 'senha'];
                foreach ($required as $field) {
                    if (!isset($client[$field]) || empty(trim($client[$field]))) {
                        $skipped++;
                        continue 2;
                    }
                }

                $cpf = preg_replace('/[^0-9]/', '', $client['cpf']);
                if (!validateCPF($cpf)) {
                    $skipped++;
                    error_log("CPF inválido: {$client['cpf']}");
                    continue;
                }

                // Verificar CPF duplicado
                $checkCPF = $conn->prepare("SELECT COUNT(*) FROM clients WHERE cpf = ?");
                $checkCPF->execute([$cpf]);
                if ($checkCPF->fetchColumn() > 0) {
                    $skipped++;
                    error_log("CPF duplicado: {$cpf}");
                    continue;
                }

                // Verificar Serial único
                $checkSerial = $conn->prepare("SELECT COUNT(*) FROM clients WHERE serial = ?");
                $checkSerial->execute([$client['serial']]);
                if ($checkSerial->fetchColumn() > 0) {
                    $skipped++;
                    error_log("Serial duplicado: {$client['serial']}");
                    continue;
                }

                // Validações adicionais
                if (!empty($client['telefone']) && strlen(preg_replace('/[^0-9]/', '', $client['telefone'])) < 10) {
                    $skipped++;
                    continue;
                }

                if (!empty($client['vencimento']) && !in_array($client['vencimento'], [10, 20, 30])) {
                    $skipped++;
                    continue;
                }

                if (!empty($client['cidade']) && !in_array($client['cidade'], ['Ipixuna', 'Eirunepé', 'Carauari', 'Itamarati'])) {
                    $skipped++;
                    continue;
                }

                // Resolver planId (pode vir como nome ou ID)
                $planId = $client['plano'];
                if (!is_numeric($planId)) {
                    // Buscar ID pelo nome
                    $planStmt = $conn->prepare("SELECT id FROM plans WHERE name = ? OR name LIKE ?");
                    $planStmt->execute([$planId, "%{$planId}%"]);
                    $planResult = $planStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$planResult) {
                        $skipped++;
                        error_log("Plano não encontrado: {$planId}");
                        continue;
                    }
                    $planId = $planResult['id'];
                }

                // Inserir cliente
                $stmt = $conn->prepare("
                    INSERT INTO clients (cpf, serial, name, address, number, complement, city, dueDay, phone, birthDate, observation, planId, installer, pppoe, password, active)
                    VALUES (:cpf, :serial, :name, :address, :number, :complement, :city, :dueDay, :phone, :birthDate, :observation, :planId, :installer, :pppoe, :password, :active)
                ");
                
                $stmt->execute([
                    'cpf' => $cpf,
                    'serial' => $client['serial'],
                    'name' => $client['nome'],
                    'address' => $client['endereço'],
                    'number' => $client['número'] ?? null,
                    'complement' => $client['complemento'] ?? null,
                    'city' => $client['cidade'] ?? null,
                    'dueDay' => $client['vencimento'] ?? null,
                    'phone' => !empty($client['telefone']) ? preg_replace('/[^0-9]/', '', $client['telefone']) : null,
                    'birthDate' => $client['data nascimento'] ?? null,
                    'observation' => $client['observação'] ?? null,
                    'planId' => $planId,
                    'installer' => $client['instalador'],
                    'pppoe' => $client['pppoe'],
                    'password' => $client['senha'],
                    'active' => 1
                ]);

                $imported++;
                error_log("Cliente importado: {$client['nome']}");
            } catch (Exception $e) {
                $skipped++;
                error_log("Erro ao importar cliente {$index}: " . $e->getMessage());
            }
        }

        // Invalidar cache
        if ($redis && $imported > 0) {
            $redis->del('clients_list');
            error_log("Cache invalidado para clientes (IMPORT)");
        }

        echo json_encode([
            'success' => true, 
            'message' => "Importação concluída: {$imported} importado(s), {$skipped} pulado(s)",
            'imported' => $imported,
            'skipped' => $skipped
        ]);
    } else {
        sendError(405, 'Método não permitido');
    }
} catch (PDOException $e) {
    sendError(500, 'Erro no banco de dados', $e->getMessage());
} catch (Exception $e) {
    sendError(500, 'Erro geral', $e->getMessage());
}
?>