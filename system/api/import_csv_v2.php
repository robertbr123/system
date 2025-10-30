<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// Simular autenticação para testes
$_SESSION['user_id'] = 1;

require_once 'config.php';

// Dados de teste
$testData = json_decode(file_get_contents('php://input'), true);

if (!$testData || !isset($testData['clients'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhum cliente fornecido']);
    exit;
}

// Função para validar CPF
function validateCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $p = 0; $p < $t; $p++) {
            $d += $cpf[$p] * (($t + 1) - $p);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$t] != $d) return false;
    }
    return true;
}

$clients = $testData['clients'];
$imported = 0;
$skipped = 0;
$errors = [];

foreach ($clients as $index => $client) {
    try {
        // Validar campos obrigatórios
        $required = ['nome', 'cpf', 'serial', 'endereço', 'plano', 'instalador', 'pppoe', 'senha'];
        $missing = [];
        foreach ($required as $field) {
            if (!isset($client[$field]) || empty(trim((string)$client[$field]))) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": Campos faltando: " . implode(', ', $missing);
            continue;
        }

        $cpf = preg_replace('/[^0-9]/', '', $client['cpf']);
        
        if (!validateCPF($cpf)) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": CPF inválido: " . $client['cpf'];
            continue;
        }

        // Verificar CPF duplicado
        $checkCPF = $conn->prepare("SELECT COUNT(*) as cnt FROM clients WHERE cpf = ?");
        $checkCPF->execute([$cpf]);
        $result = $checkCPF->fetch(PDO::FETCH_ASSOC);
        if ($result['cnt'] > 0) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": CPF duplicado";
            continue;
        }

        // Verificar Serial único
        $checkSerial = $conn->prepare("SELECT COUNT(*) as cnt FROM clients WHERE serial = ?");
        $checkSerial->execute([$client['serial']]);
        $result = $checkSerial->fetch(PDO::FETCH_ASSOC);
        if ($result['cnt'] > 0) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": Serial duplicado";
            continue;
        }

        // Inserir cliente
        $stmt = $conn->prepare("
            INSERT INTO clients (cpf, serial, name, address, planId, installer, pppoe, password, active)
            VALUES (:cpf, :serial, :name, :address, :planId, :installer, :pppoe, :password, :active)
        ");
        
        $stmt->execute([
            'cpf' => $cpf,
            'serial' => $client['serial'],
            'name' => $client['nome'],
            'address' => $client['endereço'],
            'planId' => $client['plano'],
            'installer' => $client['instalador'],
            'pppoe' => $client['pppoe'],
            'password' => $client['senha'],
            'active' => 1
        ]);

        $imported++;
    } catch (PDOException $e) {
        $skipped++;
        $errors[] = "Cliente " . ($index + 1) . ": BD Error: " . $e->getMessage();
    } catch (Exception $e) {
        $skipped++;
        $errors[] = "Cliente " . ($index + 1) . ": " . $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'message' => "Importação concluída: $imported importado(s), $skipped pulado(s)",
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
