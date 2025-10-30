<?php
// Script de teste para importação CSV
session_start();

// Simular login
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Admin';

require_once 'config.php';

// Dados de teste
$testClients = [
    [
        'nome' => 'Cliente Teste 1',
        'cpf' => '111.111.111-11',
        'serial' => 'TESTMAC001',
        'endereço' => 'Rua Teste',
        'plano' => '30 Mbps',
        'instalador' => 'Carlos',
        'pppoe' => 'test_user1',
        'senha' => 'pass123'
    ],
    [
        'nome' => 'Cliente Teste 2',
        'cpf' => '222.222.222-22',
        'serial' => 'TESTMAC002',
        'endereço' => 'Avenida Teste',
        'número' => '100',
        'plano' => '50 Mbps',
        'instalador' => 'Pedro',
        'pppoe' => 'test_user2',
        'senha' => 'pass456',
        'cidade' => 'Ipixuna',
        'vencimento' => 10
    ]
];

// Simular requisição POST
$_POST['action'] = 'import_csv';
$_GET['action'] = 'import_csv';
$_SERVER['REQUEST_METHOD'] = 'POST';

// Processar validação
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

$imported = 0;
$skipped = 0;
$errors = [];

try {
    foreach ($testClients as $index => $client) {
        // Validar campos obrigatórios
        $required = ['nome', 'cpf', 'serial', 'endereço', 'plano', 'instalador', 'pppoe', 'senha'];
        foreach ($required as $field) {
            if (!isset($client[$field]) || empty(trim($client[$field]))) {
                $skipped++;
                $errors[] = "Cliente {$index}: Campo '{$field}' obrigatório ausente";
                continue 2;
            }
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
            $errors[] = "Cliente {$index}: CPF duplicado ({$cpf})";
            continue;
        }

        // Verificar Serial único
        $checkSerial = $conn->prepare("SELECT COUNT(*) FROM clients WHERE serial = ?");
        $checkSerial->execute([$client['serial']]);
        if ($checkSerial->fetchColumn() > 0) {
            $skipped++;
            $errors[] = "Cliente {$index}: Serial duplicado ({$client['serial']})";
            continue;
        }

        // Inserir cliente
        $stmt = $conn->prepare("
            INSERT INTO clients (cpf, serial, name, address, city, phone, dueDay, planId, installer, pppoe, password, active)
            VALUES (:cpf, :serial, :name, :address, :city, :phone, :dueDay, :planId, :installer, :pppoe, :password, :active)
        ");
        
        $stmt->execute([
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

        $imported++;
    }

    echo "✅ Teste de Importação Concluído!\n";
    echo "Importados: $imported\n";
    echo "Pulados: $skipped\n";
    
    if (!empty($errors)) {
        echo "\nErros encontrados:\n";
        foreach ($errors as $err) {
            echo "- $err\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Erro no teste: " . $e->getMessage() . "\n";
}
?>
