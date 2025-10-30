<?php
require_once 'config.php';
require_once 'vendor/autoload.php'; // Para Twilio ou outra biblioteca WhatsApp

use Twilio\Rest\Client;

// Configurações do WhatsApp (usando Twilio Sandbox para testes)
$accountSid = 'SUA_ACCOUNT_SID';
$authToken = 'SEU_AUTH_TOKEN';
$twilioNumber = 'whatsapp:+14155238886'; // Número do Twilio Sandbox
$client = new Client($accountSid, $authToken);

function sendWhatsAppMessage($to, $message) {
    global $client, $twilioNumber;
    try {
        $message = $client->messages->create(
            $to, // Número do destinatário, ex.: 'whatsapp:+5511999999999'
            [
                'from' => $twilioNumber,
                'body' => $message
            ]
        );
        return ['success' => true, 'sid' => $message->sid];
    } catch (Exception $e) {
        error_log("Erro ao enviar WhatsApp: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Endpoint para enviar mensagem
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['to']) || !isset($input['message'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros to e message são obrigatórios']);
        exit;
    }
    $result = sendWhatsAppMessage('whatsapp:' . $input['to'], $input['message']);
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
}
?>