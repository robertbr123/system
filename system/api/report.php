<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

try {
    // Verificar autenticação
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }

    // Conectar ao banco
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar ação solicitada
    $action = $_GET['action'] ?? 'pdf';
    
    // Se solicitar estatísticas
    if ($action === 'stats') {
        // Total de clientes
        $stmt = $conn->query("SELECT COUNT(*) as total FROM clients");
        $totalClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Clientes por plano
        $stmt = $conn->query("
            SELECT p.name, COUNT(c.id) as count
            FROM clients c
            LEFT JOIN plans p ON c.planId = p.id
            GROUP BY c.planId
            ORDER BY count DESC
        ");
        $clientsByPlan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Clientes por cidade
        $stmt = $conn->query("
            SELECT city, COUNT(*) as count
            FROM clients
            WHERE city IS NOT NULL AND city != ''
            GROUP BY city
            ORDER BY count DESC
            LIMIT 10
        ");
        $clientsByCity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Clientes por instalador
        $stmt = $conn->query("
            SELECT installer, COUNT(*) as count
            FROM clients
            WHERE installer IS NOT NULL AND installer != ''
            GROUP BY installer
            ORDER BY count DESC
            LIMIT 10
        ");
        $clientsByInstaller = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'totalClients' => (int)$totalClients,
            'clientsByPlan' => $clientsByPlan,
            'clientsByCity' => $clientsByCity,
            'clientsByInstaller' => $clientsByInstaller
        ]);
        exit;
    }
    
    // Verificar cache do PDF
    if ($action === 'pdf') {
        $cacheDir = sys_get_temp_dir() . '/report_cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Hash do cache (1 hora)
        $cacheHash = date('YmdH');
        $cacheFile = $cacheDir . '/report_' . $cacheHash . '.pdf';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="relatorio_clientes.pdf"');
            header('Content-Length: ' . filesize($cacheFile));
            header('X-Cache: HIT');
            readfile($cacheFile);
            exit;
        }
    }

    // Buscar clientes
    $stmt = $conn->prepare("
        SELECT c.*, p.name AS plan_name
        FROM clients c
        LEFT JOIN plans p ON c.planId = p.id
    ");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Funções de formatação
    function formatCPF($cpf) {
        if (!$cpf) return '';
        $cpf = preg_replace('/\D/', '', $cpf);
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }

    function formatPhone($phone) {
        if (!$phone) return '';
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
        } else if (strlen($phone) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
        }
        return $phone;
    }

    function formatDate($date) {
        if (!$date) return '';
        $parts = explode('-', $date);
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }

    // Gerar conteúdo LaTeX
    $latexContent = "
\\documentclass[a4paper,landscape]{article}
\\usepackage[utf8]{inputenc}
\\usepackage[T1]{fontenc}
\\usepackage{geometry}
\\geometry{left=1cm,right=1cm,top=1cm,bottom=1cm}
\\usepackage{longtable}
\\usepackage{booktabs}
\\usepackage{pdflscape}
\\usepackage{xcolor}
\\usepackage{colortbl}
\\definecolor{headerblue}{RGB}{30,64,175}
\\definecolor{rowgray}{RGB}{245,245,245}
\\begin{document}
\\begin{center}
  \\LARGE\\textbf{Relatório de Clientes - Sistema ERP}\\\\
  \\small Gerado em: \\today
\\end{center}
\\vspace{0.5cm}
\\begin{longtable}{|p{2.5cm}|p{2.5cm}|p{4cm}|p{5cm}|p{2cm}|p{2cm}|p{2cm}|p{1.5cm}|p{2cm}|p{2cm}|p{3cm}|p{2cm}|p{2cm}|p{2cm}|p{2cm}|}
  \\hline
  \\rowcolor{headerblue}
  \\color{white}\\textbf{CPF} &
  \\color{white}\\textbf{Serial} &
  \\color{white}\\textbf{Nome} &
  \\color{white}\\textbf{Endereço} &
  \\color{white}\\textbf{Número} &
  \\color{white}\\textbf{Compl.} &
  \\color{white}\\textbf{Cidade} &
  \\color{white}\\textbf{Venc.} &
  \\color{white}\\textbf{Telefone} &
  \\color{white}\\textbf{Nasc.} &
  \\color{white}\\textbf{Observação} &
  \\color{white}\\textbf{Plano} &
  \\color{white}\\textbf{Instalador} &
  \\color{white}\\textbf{PPPoE} &
  \\color{white}\\textbf{Senha} \\\\
  \\hline
  \\endhead
";

        // Escape LaTeX special chars to avoid compilation issues
        function latexEscape($s) {
            if ($s === null) return '';
            $replacements = [
                '\\' => '\\textbackslash{}',
                '{' => '\\{', '}' => '\\}',
                '$' => '\\$', '&' => '\\&', '%' => '\\%', '#' => '\\#', '_' => '\\_',
                '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}'
            ];
            return strtr($s, $replacements);
        }

    foreach ($clients as $index => $client) {
        $rowColor = $index % 2 === 0 ? '\\rowcolor{rowgray}' : '';
        $latexContent .= "
  $rowColor
      " . latexEscape(formatCPF($client['cpf'])) . " &
      " . ($client['serial'] ? latexEscape($client['serial']) : '') . " &
      " . ($client['name'] ? latexEscape($client['name']) : '') . " &
      " . ($client['address'] ? latexEscape($client['address']) : '') . " &
  " . ($client['number'] ?? '') . " &
  " . ($client['complement'] ?? '') . " &
  " . ($client['city'] ?? '') . " &
  " . ($client['dueDay'] ?? '') . " &
      " . latexEscape(formatPhone($client['phone'] ?? '')) . " &
      " . latexEscape(formatDate($client['birthDate'] ?? '')) . " &
      " . ($client['observation'] ? latexEscape(str_replace("\n", ' ', $client['observation'])) : '') . " &
      " . ($client['plan_name'] ? latexEscape($client['plan_name']) : '') . " &
      " . ($client['installer'] ? latexEscape($client['installer']) : '') . " &
      " . ($client['pppoe'] ? latexEscape($client['pppoe']) : '') . " &
      " . ($client['password'] ? latexEscape($client['password']) : '') . " \\\\
  \\hline
";
    }

    $latexContent .= "
  \\end{longtable}
\\end{document}
";

    // Salvar o arquivo LaTeX temporário
    $tempDir = sys_get_temp_dir();
    $latexFile = $tempDir . '/report.tex';
    $pdfFile = $tempDir . '/report.pdf';
    file_put_contents($latexFile, $latexContent);

    // Compilar o LaTeX em PDF usando latexmk (escapar caminhos com espaços)
    // Verificar se latexmk existe
    $which = shell_exec('which latexmk 2>/dev/null');
    if (!$which) {
        http_response_code(500);
        echo json_encode(['error' => 'LaTeX não está instalado no servidor. Instale texlive-latex-extra ou miktex.']);
        exit;
    }
    
    $command = "latexmk -pdf -interaction=nonstopmode -outdir=" . escapeshellarg($tempDir) . ' ' . escapeshellarg($latexFile) . " 2>&1";
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        http_response_code(500);
        // Extrair erro relevante do log
        $errorLog = implode("\n", $output);
        $relevantError = 'Erro ao compilar LaTeX. ';
        
        // Tentar extrair erro útil
        if (strpos($errorLog, 'Error') !== false) {
            preg_match('/Error.*/', $errorLog, $matches);
            if (!empty($matches)) {
                $relevantError .= $matches[0];
            }
        }
        
        echo json_encode(['error' => $relevantError, 'details' => array_slice($output, -10)]);
        exit;
    }

    // Enviar o PDF como download
    if (file_exists($pdfFile)) {
        // Salvar no cache
        if (isset($cacheFile)) {
            copy($pdfFile, $cacheFile);
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="relatorio_clientes.pdf"');
        header('Content-Length: ' . filesize($pdfFile));
        header('X-Cache: MISS');
        readfile($pdfFile);

        // Limpar arquivos temporários
        unlink($latexFile);
        unlink($pdfFile);
        array_map('unlink', glob("$tempDir/report.*"));
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PDF não foi gerado']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
?>