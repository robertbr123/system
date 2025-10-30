<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'vendor/autoload.php'; // Biblioteca Twilio

use Twilio\Rest\Client;

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

try {
    // Configurações do Twilio
    $accountSid = 'SUA_ACCOUNT_SID'; // Substitua pelo seu Account SID
    $authToken = 'SEU_AUTH_TOKEN'; // Substitua pelo seu Auth Token
    $twilioNumber = 'whatsapp:+14155238886'; // Número do Twilio Sandbox para WhatsApp
    $client = new Client($accountSid, $authToken);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['to']) || !isset($input['message'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros to e message são obrigatórios']);
        exit;
    }

    $message = $client->messages->create(
        $input['to'], // Número do destinatário, ex.: 'whatsapp:+5511999999999'
        [
            'from' => $twilioNumber,
            'body' => $input['message']
        ]
    );

    echo json_encode(['success' => true, 'sid' => $message->sid]);
} catch (Exception $e) {
    http_response_code(500);
    $errorMessage = 'Erro ao enviar mensagem WhatsApp: ' . $e->getMessage();
    error_log($errorMessage);
    echo json_encode(['error' => $errorMessage]);
}
?>