<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// Simular autenticação para testes
$_SESSION['user_id'] = 1;

require_once 'config.php';

// Dados de teste
$testData = json_decode(file_get_contents('php://input'), true);

// Se vem da URL, usar dados de teste padrão
if (!$testData || !isset($testData['clients'])) {
    $testData = [
        'clients' => [
            [
                'nome' => 'Test Client 1',
                'cpf' => '555.123.456-78',
                'serial' => 'TESTC001',
                'endereço' => 'Test Street 1',
                'plano' => '30 Mbps',
                'instalador' => 'Admin',
                'pppoe' => 'test1',
                'senha' => 'pass1'
            ],
            [
                'nome' => 'Test Client 2',
                'cpf' => '444.987.321-65',
                'serial' => 'TESTC002',
                'endereço' => 'Test Street 2',
                'plano' => '50 Mbps',
                'instalador' => 'Admin',
                'pppoe' => 'test2',
                'senha' => 'pass2'
            ]
        ]
    ];
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
            if (!isset($client[$field]) || empty(trim($client[$field]))) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            $skipped++;
            $errors[] = "Cliente {$index}: Campos obrigatórios ausentes: " . implode(', ', $missing);
            continue;
        }

        $cpf = preg_replace('/[^0-9]/', '', $client['cpf']);
        if (!validateCPF($cpf)) {
            $skipped++;
            $errors[] = "Cliente {$index}: CPF inválido ({$client['cpf']})";
            continue;
        }

        // Verificar CPF duplicado
        $checkCPF = $conn->prepare("SELECT COUNT(*) FROM clients WHERE cpf = ?");
        $checkCPF->execute([$cpf]);
        if ($checkCPF->fetchColumn() > 0) {
            $skipped++;
            $errors[] = "Cliente {$index}: CPF já existe ({$cpf})";
            continue;
        }

        // Verificar Serial único
        $checkSerial = $conn->prepare("SELECT COUNT(*) FROM clients WHERE serial = ?");
        $checkSerial->execute([$client['serial']]);
        if ($checkSerial->fetchColumn() > 0) {
            $skipped++;
            $errors[] = "Cliente {$index}: Serial já existe ({$client['serial']})";
            continue;
        }

        // Inserir cliente
        $stmt = $conn->prepare("
            INSERT INTO clients (cpf, serial, name, address, city, phone, dueDay, planId, installer, pppoe, password, active)
            VALUES (:cpf, :serial, :name, :address, :city, :phone, :dueDay, :planId, :installer, :pppoe, :password, :active)
        ");
        
        $result = $stmt->execute([
            'cpf' => $cpf,
            'serial' => $client['serial'],
            'name' => $client['nome'],
            'address' => $client['endereço'],
            'city' => $client['cidade'] ?? null,
            'phone' => null,
            'dueDay' => $client['vencimento'] ?? null,
            'planId' => $client['plano'],
            'installer' => $client['instalador'],
            'pppoe' => $client['pppoe'],
            'password' => $client['senha'],
            'active' => 1
        ]);

        if ($result) {
            $imported++;
        }
    } catch (Exception $e) {
        $skipped++;
        $errors[] = "Cliente {$index}: " . $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'message' => "Importação concluída: {$imported} importado(s), {$skipped} pulado(s)",
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
