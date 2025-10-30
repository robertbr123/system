<?php
// Simple Mikrotik RouterOS API endpoint
// Supports actions: test_connection, identity, interfaces, ip_addresses, ppp_active, hotspot_active, run

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

class RouterOSApi
{
    private $sock = null;
    private $connected = false;

    public function connect($host, $port = 8728, $timeout = 5, $tls = false)
    {
        if ($tls) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
                ]
            ]);
            $this->sock = @stream_socket_client('tls://' . $host . ':' . (int)$port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        } else {
            $this->sock = @fsockopen($host, (int)$port, $errno, $errstr, $timeout);
        }
        if (!$this->sock) {
            throw new Exception("Falha na conexão: $errstr ($errno)");
        }
        stream_set_timeout($this->sock, $timeout);
        $this->connected = true;
    }

    public function login($username, $password)
    {
        // RouterOS 6.43+ one-step login
        $this->writeSentence(['/login', '=name=' . $username, '=password=' . $password]);
        $reply = $this->readSentence();
        if (!$reply || $reply[0] !== '!done') {
            $detail = isset($reply[1]) ? $reply[1] : 'resposta inválida do roteador';
            throw new Exception('Falha no login: ' . $detail);
        }
        return true;
    }

    public function cmd(array $words)
    {
        $this->writeSentence($words);
        $responses = [];
        while (true) {
            $res = $this->readSentence();
            if ($res === null) break;
            if (count($res)) {
                if ($res[0] === '!done') break;
                if ($res[0] === '!trap') {
                    $attrs = $this->parseAttributes($res);
                    $msg = isset($attrs['message']) ? $attrs['message'] : 'Erro desconhecido no roteador';
                    throw new Exception('RouterOS erro: ' . $msg);
                }
            }
            $responses[] = $this->parseAttributes($res);
        }
        return $responses;
    }

    public function disconnect()
    {
        if ($this->sock) {
            fclose($this->sock);
            $this->sock = null;
            $this->connected = false;
        }
    }

    private function writeSentence(array $words)
    {
        foreach ($words as $w) {
            $this->writeWord($w);
        }
        $this->writeWord(''); // sentence terminator
    }

    private function writeWord($word)
    {
        $len = strlen($word);
        $this->writeLen($len);
        if ($len > 0) {
            $this->writeBytes($word);
        }
    }

    private function writeLen($len)
    {
        if ($len < 0x80) {
            $this->writeBytes(chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $this->writeBytes(chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            $this->writeBytes(chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            $this->writeBytes(chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            $this->writeBytes(chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
    }

    private function writeBytes($str)
    {
        $len = strlen($str);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($this->sock, substr($str, $written));
            if ($n === false) throw new Exception('Falha ao escrever no socket');
            $written += $n;
        }
    }

    private function readSentence()
    {
        $sentence = [];
        while (true) {
            $word = $this->readWord();
            if ($word === null) return null; // EOF
            if ($word === '') break; // sentence end
            $sentence[] = $word;
        }
        return $sentence;
    }

    private function readWord()
    {
        $len = $this->readLen();
        if ($len === null) return null;
        if ($len === 0) return '';
        $data = '';
        $read = 0;
        while ($read < $len) {
            $chunk = fread($this->sock, $len - $read);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out']) {
                    throw new Exception('Tempo esgotado ao ler do socket');
                }
                return null;
            }
            $data .= $chunk;
            $read += strlen($chunk);
        }
        return $data;
    }

    private function readLen()
    {
        $c = fgetc($this->sock);
        if ($c === false) return null;
        $len = ord($c);
        if ($len < 0x80) return $len;
        if (($len & 0xE0) == 0x80) {
            $c2 = ord(fgetc($this->sock));
            return (($len & 0x1F) << 8) + $c2;
        }
        if (($len & 0xF0) == 0xC0) {
            $c2 = ord(fgetc($this->sock));
            $c3 = ord(fgetc($this->sock));
            return (($len & 0x0F) << 16) + ($c2 << 8) + $c3;
        }
        if (($len & 0xF8) == 0xE0) {
            $c2 = ord(fgetc($this->sock));
            $c3 = ord(fgetc($this->sock));
            $c4 = ord(fgetc($this->sock));
            return (($len & 0x07) << 24) + ($c2 << 16) + ($c3 << 8) + $c4;
        }
        if (($len & 0xFC) == 0xF0) {
            $c2 = ord(fgetc($this->sock));
            $c3 = ord(fgetc($this->sock));
            $c4 = ord(fgetc($this->sock));
            $c5 = ord(fgetc($this->sock));
            return (($len & 0x03) << 32) + ($c2 << 24) + ($c3 << 16) + ($c4 << 8) + $c5;
        }
        return null;
    }

    private function parseAttributes(array $sentence)
    {
        $out = [];
        foreach ($sentence as $word) {
            if (strlen($word) && $word[0] === '=') {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $out[$parts[0]] = $parts[1];
                }
            }
        }
        return $out;
    }
}

function json_input()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    return $data;
}

try {
    $input = json_input();

    $host = isset($input['host']) ? trim($input['host']) : '';
    $user = isset($input['username']) ? trim($input['username']) : '';
    $pass = isset($input['password']) ? $input['password'] : '';
    $port = isset($input['port']) ? (int)$input['port'] : 8728;
    $tls = !empty($input['tls']);
    $action = isset($input['action']) ? $input['action'] : 'test_connection';

    if (!$host || !$user || !$pass) {
        http_response_code(400);
        echo json_encode(['error' => 'host, username e password são obrigatórios']);
        exit;
    }

    $api = new RouterOSApi();
    try {
        $api->connect($host, $port, 5, $tls);
        $api->login($user, $pass);
    } catch (Exception $e) {
        $api->disconnect();
        throw $e;
    }

    $result = null;

    $run_action = function($name, $params = []) use (&$api) {
        switch ($name) {
            case 'test_connection':
                $id = $api->cmd(['/system/identity/print']);
                $ver = $api->cmd(['/system/resource/print']);
                return [
                    'identity' => $id[0]['name'] ?? null,
                    'version' => $ver[0]['version'] ?? null,
                    'uptime' => $ver[0]['uptime'] ?? null,
                    'board' => $ver[0]['board-name'] ?? null,
                    'cpu_load' => $ver[0]['cpu-load'] ?? null,
                ];
            case 'interfaces':
                return $api->cmd(['/interface/print']);
            case 'ip_addresses':
                return $api->cmd(['/ip/address/print']);
            case 'ppp_active':
                return $api->cmd(['/ppp/active/print']);
            case 'hotspot_active':
                return $api->cmd(['/ip/hotspot/active/print']);
            case 'log':
                $limit = isset($params['limit']) ? (int)$params['limit'] : 0;
                $logs = $api->cmd(['/log/print']);
                if ($limit > 0 && is_array($logs) && count($logs) > $limit) {
                    $logs = array_slice($logs, -$limit);
                }
                return $logs;
            case 'ppp_profiles':
                return $api->cmd(['/ppp/profile/print']);
            case 'ppp_secrets':
                return $api->cmd(['/ppp/secret/print']);
            default:
                throw new Exception('Ação não permitida no batch: ' . $name);
        }
    };

    switch ($action) {
        case 'test_connection':
            $result = $run_action('test_connection');
            break;
        case 'interfaces':
            $result = $run_action('interfaces');
            break;
        case 'ip_addresses':
            $result = $run_action('ip_addresses');
            break;
        case 'ppp_active':
            $result = $run_action('ppp_active');
            break;
        case 'hotspot_active':
            $result = $run_action('hotspot_active');
            break;
        case 'log':
            $result = $run_action('log', ['limit' => isset($input['limit']) ? (int)$input['limit'] : 0]);
            break;
        case 'ppp_profiles':
            $result = $run_action('ppp_profiles');
            break;
        case 'ppp_secrets':
            $result = $run_action('ppp_secrets');
            break;
        case 'batch':
            $actions = isset($input['actions']) && is_array($input['actions']) ? $input['actions'] : [];
            if (!$actions) throw new Exception('Lista de ações vazia');
            $out = [];
            foreach ($actions as $item) {
                if (is_string($item)) {
                    $name = $item; $params = [];
                } elseif (is_array($item)) {
                    $name = $item['name'] ?? '';
                    $params = isset($item['params']) && is_array($item['params']) ? $item['params'] : [];
                } else {
                    throw new Exception('Item inválido no batch');
                }
                if (!$name) throw new Exception('Ação inválida no batch');
                $out[$name] = $run_action($name, $params);
            }
            $result = $out;
            break;
        case 'ppp_set_disabled':
            $id = isset($input['id']) ? trim($input['id']) : '';
            $name = isset($input['name']) ? trim($input['name']) : '';
            $disabled = isset($input['disabled']) ? (bool)$input['disabled'] : null;
            if ($disabled === null) {
                throw new Exception('Parâmetro "disabled" é obrigatório (true/false)');
            }
            if ($id === '' && $name === '') {
                throw new Exception('Informe id ou name do secret');
            }
            if ($id === '' && $name !== '') {
                $found = $api->cmd(['/ppp/secret/print', '?name=' . $name]);
                if (!$found || !isset($found[0]['.id'])) {
                    throw new Exception('Secret não encontrado: ' . $name);
                }
                $id = $found[0]['.id'];
            }
            $api->cmd(['/ppp/secret/set', '=.id=' . $id, '=disabled=' . ($disabled ? 'yes' : 'no')]);
            $result = ['updated' => true, 'id' => $id, 'name' => $name, 'disabled' => $disabled];
            break;
        case 'ppp_add_secret':
            $name = isset($input['name']) ? trim($input['name']) : '';
            $password = isset($input['password']) ? (string)$input['password'] : '';
            $service = isset($input['service']) && $input['service'] !== '' ? $input['service'] : 'pppoe';
            $profile = isset($input['profile']) ? trim($input['profile']) : '';
            $comment = isset($input['comment']) ? trim($input['comment']) : '';
            if ($name === '' || $password === '') {
                throw new Exception('Campos obrigatórios: name e password');
            }
            if (!preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $name)) {
                throw new Exception('Nome inválido. Use 3-64 chars (A-Z, a-z, 0-9, ., _, -)');
            }
            $words = ['/ppp/secret/add', '=name=' . $name, '=password=' . $password, '=service=' . $service];
            if ($profile !== '') { $words[] = '=profile=' . $profile; }
            if ($comment !== '') { $words[] = '=comment=' . $comment; }
            $api->cmd($words); // se ocorrer erro, Exception será lançada
            $result = ['created' => true, 'name' => $name];
            break;
        case 'run':
            // Run arbitrary command with optional queries, but restrict to read-only paths for safety
            $allowedPrefixes = [
                '/interface', '/ip/address', '/system/resource', '/ip/hotspot/active', '/ppp/active', '/log'
            ];
            $cmd = isset($input['command']) ? $input['command'] : '';
            if (!$cmd || $cmd[0] !== '/') {
                throw new Exception('Comando inválido');
            }
            $allowed = false;
            foreach ($allowedPrefixes as $p) {
                if (strpos($cmd, $p) === 0) { $allowed = true; break; }
            }
            if (!$allowed) {
                throw new Exception('Comando não permitido por segurança');
            }
            $words = [$cmd];
            if (isset($input['arguments']) && is_array($input['arguments'])) {
                foreach ($input['arguments'] as $k => $v) {
                    $words[] = '=' . $k . '=' . $v;
                }
            }
            $result = $api->cmd($words);
            break;
        default:
            throw new Exception('Ação inválida');
    }

    $api->disconnect();
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
