<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    error_log('Erro: Acesso não autenticado a dashboard.php');
    exit;
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Total de Clientes
    $stmt = $conn->query("SELECT COUNT(*) as total FROM clients");
    $totalClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Clientes Ativos
    $stmt = $conn->query("SELECT COUNT(*) as active FROM clients WHERE status = 'ativo'");
    $activeClients = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;

    // Clientes Inadimplentes
    $overdueClients = $totalClients - $activeClients;

    // Vencimentos Hoje
    $stmt = $conn->query("SELECT COUNT(*) as dueToday FROM clients WHERE dueDay = DAY(CURDATE())");
    $dueToday = $stmt->fetch(PDO::FETCH_ASSOC)['dueToday'] ?? 0;

    // Vencimentos nos Próximos 7 Dias
    $stmt = $conn->query("
        SELECT COUNT(*) as dueWeek 
        FROM clients 
        WHERE dueDay BETWEEN DAY(CURDATE()) AND DAY(CURDATE()) + 7
    ");
    $dueWeek = $stmt->fetch(PDO::FETCH_ASSOC)['dueWeek'] ?? 0;

    // Planos Ativos
    $stmt = $conn->query("SELECT COUNT(DISTINCT c.planId) as activePlans FROM clients c JOIN plans p ON c.planId = p.id");
    $activePlans = $stmt->fetch(PDO::FETCH_ASSOC)['activePlans'] ?? 0;

    // Total de Instaladores
    $stmt = $conn->query("SELECT COUNT(*) as totalInstallers FROM installers");
    $totalInstallers = $stmt->fetch(PDO::FETCH_ASSOC)['totalInstallers'] ?? 0;

    // Novos Clientes no Último Mês
    $stmt = $conn->query("
        SELECT COUNT(*) as newClients 
        FROM clients 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $newClientsMonth = $stmt->fetch(PDO::FETCH_ASSOC)['newClients'] ?? 0;

    // Clientes por Cidade
    $stmt = $conn->query("
        SELECT city, COUNT(*) as count 
        FROM clients 
        GROUP BY city 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $clientsByCity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Clientes por Plano
    $stmt = $conn->query("
        SELECT p.name as plan_name, COUNT(c.cpf) as count 
        FROM clients c
        LEFT JOIN plans p ON c.planId = p.id 
        GROUP BY p.id, p.name 
        ORDER BY count DESC
    ");
    $clientsByPlan = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Crescimento de Clientes (últimos 6 meses)
    $stmt = $conn->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
        FROM clients 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $clientGrowth = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Clientes com Vencimento Próximo (com filtros)
    $query = "
        SELECT c.name, c.cpf, p.name as plan_name, c.city, c.installer, c.dueDay, c.phone_number
        FROM clients c
        LEFT JOIN plans p ON c.planId = p.id
        WHERE c.dueDay BETWEEN DAY(CURDATE()) AND DAY(CURDATE()) + 7
    ";
    $params = [];
    if (!empty($_GET['filterCity'])) {
        $query .= " AND c.city = ?";
        $params[] = $_GET['filterCity'];
    }
    if (!empty($_GET['filterPlan'])) {
        $query .= " AND c.planId = ?";
        $params[] = $_GET['filterPlan'];
    }
    if (!empty($_GET['filterInstaller'])) {
        $query .= " AND c.installer = ?";
        $params[] = $_GET['filterInstaller'];
    }
    $query .= " ORDER BY c.dueDay";
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $upcomingDue = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Log para depuração
    error_log('Dashboard carregado com sucesso: ' . json_encode([
        'totalClients' => $totalClients,
        'activeClients' => $activeClients,
        'overdueClients' => $overdueClients,
        'dueToday' => $dueToday,
        'dueWeek' => $dueWeek,
        'activePlans' => $activePlans,
        'totalInstallers' => $totalInstallers,
        'newClientsMonth' => $newClientsMonth,
        'clientsByCity' => count($clientsByCity),
        'clientsByPlan' => count($clientsByPlan),
        'clientGrowth' => count($clientGrowth),
        'upcomingDue' => count($upcomingDue)
    ]));

    echo json_encode([
        'totalClients' => $totalClients,
        'activeClients' => $activeClients,
        'overdueClients' => $overdueClients,
        'dueToday' => $dueToday,
        'dueWeek' => $dueWeek,
        'activePlans' => $activePlans,
        'totalInstallers' => $totalInstallers,
        'newClientsMonth' => $newClientsMonth,
        'clientsByCity' => $clientsByCity,
        'clientsByPlan' => $clientsByPlan,
        'clientGrowth' => $clientGrowth,
        'upcomingDue' => $upcomingDue
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