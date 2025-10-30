<?php
// Teste completo da importação de CSV
session_start();
$_SESSION['user_id'] = 1; // Simular autenticação

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

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

// Dados de teste realistas
$testData = [
    'clients' => [
        [
            'nome' => 'João Silva Santos',
            'cpf' => '225.061.956-53', // CPF válido do teste anterior
            'serial' => 'MAC00001',
            'endereço' => 'Rua Principal',
            'número' => '100',
            'complemento' => 'Apto 101',
            'cidade' => 'Ipixuna',
            'telefone' => '92988123456',
            'data nascimento' => '1990-05-15',
            'plano' => 'PLANO BASICO',
            'instalador' => 'Carlos',
            'pppoe' => 'joao.silva',
            'senha' => 'senha123',
            'vencimento' => 10,
            'observação' => 'Cliente teste VIP'
        ],
        [
            'nome' => 'Maria Santos Oliveira',
            'cpf' => '876.043.771-57', // CPF válido do teste anterior
            'serial' => 'MAC00002',
            'endereço' => 'Avenida Central',
            'número' => '250',
            'cidade' => 'Eirunepé',
            'telefone' => '92987654321',
            'plano' => 6, // ID do plano BASICO
            'instalador' => 'Pedro',
            'pppoe' => 'maria.santos',
            'senha' => 'senha456',
            'vencimento' => 20,
            'observação' => 'Renovação mensal'
        ],
        [
            'nome' => 'Pedro Oliveira Junior',
            'cpf' => '814.384.492-75', // CPF válido do teste anterior
            'serial' => 'MAC00003',
            'endereço' => 'Rua Lateral',
            'número' => '50',
            'complemento' => 'Casa 202',
            'cidade' => 'Carauari',
            'plano' => 'PLANO 100', // Nome do plano
            'instalador' => 'João',
            'pppoe' => 'pedro.oliveira',
            'senha' => 'senha789',
            'vencimento' => 30,
            'observação' => 'Novo cliente'
        ]
    ]
];

$clients = $testData['clients'];
$imported = 0;
$skipped = 0;
$errors = [];
$details = [];

echo "=== TESTE DE IMPORTAÇÃO DE CSV ===\n\n";

foreach ($clients as $index => $client) {
    echo "Processando cliente $index: {$client['nome']}...\n";
    
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
            echo "  ✗ Campos obrigatórios ausentes\n";
            continue;
        }

        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $client['cpf']);
        
        if (!validateCPF($cpf)) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": CPF inválido: " . $client['cpf'];
            echo "  ✗ CPF inválido\n";
            continue;
        }
        echo "  ✓ CPF válido\n";

        // Verificar duplicatas
        $checkCPF = $conn->prepare("SELECT COUNT(*) as cnt FROM clients WHERE cpf = ?");
        $checkCPF->execute([$cpf]);
        if ($checkCPF->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": CPF duplicado";
            echo "  ✗ CPF já existe no sistema\n";
            continue;
        }

        $checkSerial = $conn->prepare("SELECT COUNT(*) as cnt FROM clients WHERE serial = ?");
        $checkSerial->execute([$client['serial']]);
        if ($checkSerial->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
            $skipped++;
            $errors[] = "Cliente " . ($index + 1) . ": Serial duplicado";
            echo "  ✗ Serial já existe\n";
            continue;
        }
        echo "  ✓ CPF e Serial únicos\n";

        // Resolver planId
        $planId = $client['plano'];
        if (!is_numeric($planId)) {
            $planStmt = $conn->prepare("SELECT id FROM plans WHERE name = ? OR name LIKE ?");
            $planStmt->execute([$planId, "%{$planId}%"]);
            $planResult = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$planResult) {
                $skipped++;
                $errors[] = "Cliente " . ($index + 1) . ": Plano não encontrado: " . $planId;
                echo "  ✗ Plano não encontrado\n";
                continue;
            }
            $planId = $planResult['id'];
            echo "  ✓ Plano resolvido (ID: $planId)\n";
        }

        // Inserir
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
        echo "  ✓ Importado com sucesso\n";
        $details[] = "Cliente $index importado: {$client['nome']} (CPF: {$client['cpf']})";

    } catch (PDOException $e) {
        $skipped++;
        $errors[] = "Cliente " . ($index + 1) . ": BD Error: " . $e->getMessage();
        echo "  ✗ Erro no banco: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        $skipped++;
        $errors[] = "Cliente " . ($index + 1) . ": " . $e->getMessage();
        echo "  ✗ Erro: " . $e->getMessage() . "\n";
    }
}

echo "\n=== RESULTADO FINAL ===\n";
echo "✓ Importados: $imported\n";
echo "✗ Pulados: $skipped\n";
echo "\nDetalhes:\n";
foreach ($details as $d) {
    echo "  • $d\n";
}

if (!empty($errors)) {
    echo "\nErros encontrados:\n";
    foreach ($errors as $e) {
        echo "  • $e\n";
    }
}

echo "\nJSON Response:\n";
echo json_encode([
    'success' => true,
    'message' => "Importação concluída: $imported importado(s), $skipped pulado(s)",
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors,
    'details' => $details
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
