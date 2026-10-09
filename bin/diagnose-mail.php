<?php
declare(strict_types=1);

// Executar somente em CLI, fora da pasta pública. Não envia mensagens.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$result = ['status' => 'FAILED', 'stage' => 'configuration', 'php' => PHP_VERSION];
$signals = [];
$mail = null;
$probe = null;
$exitCode = 1;

// Classifica avisos de rede sem registrar texto bruto, credenciais ou diálogo SMTP.
$recordSignals = static function (string $message) use (&$signals): void {
    $patterns = [
        'certificate verify failed' => 'CERTIFICATE_INVALID',
        'did not match expected' => 'CERTIFICATE_NAME_MISMATCH',
        'Connection refused' => 'CONNECTION_REFUSED',
        'timed out' => 'TIMEOUT',
        'getaddrinfo' => 'DNS_FAILURE',
        'php_network_getaddresses' => 'DNS_FAILURE',
        'Failed to enable crypto' => 'TLS_FAILURE',
    ];
    foreach ($patterns as $pattern => $label) {
        if (stripos($message, $pattern) !== false) {
            $signals[$label] = true;
        }
    }
};
set_error_handler(static function (int $severity, string $message) use ($recordSignals): bool {
    $recordSignals($message);
    return true;
});

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $result['config_path'] = getenv('PISCINA_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
    $config = config();
    $smtp = $config['smtp'] ?? [];
    $missing = [];
    foreach (['host', 'username', 'password', 'from'] as $field) {
        if (!is_string($smtp[$field] ?? null) || trim($smtp[$field]) === '') {
            $missing[] = $field;
        }
    }
    $result['mail_transport'] = $config['mail_transport'] ?? 'smtp';
    $result['host'] = $smtp['host'] ?? '';
    $result['port'] = (int) ($smtp['port'] ?? 0);
    $result['encryption'] = $smtp['encryption'] ?? '';
    $result['username_configured'] = !in_array('username', $missing, true);
    $result['password_configured'] = !in_array('password', $missing, true);
    $result['from_valid'] = filter_var($smtp['from'] ?? '', FILTER_VALIDATE_EMAIL) !== false;

    if ($missing) {
        $result['reason'] = 'SMTP_FIELDS_EMPTY';
        $result['missing_fields'] = $missing;
        throw new RuntimeException('Configuration check failed.');
    }
    if ($result['mail_transport'] !== 'smtp' || !$result['from_valid']
        || $result['port'] < 1 || $result['port'] > 65535
        || !in_array($result['encryption'], ['ssl', 'tls'], true)) {
        $result['reason'] = 'SMTP_CONFIGURATION_INVALID';
        throw new RuntimeException('Configuration check failed.');
    }

    $probe = new class($recordSignals) extends PHPMailer\PHPMailer\SMTP {
        public string $stage = 'connection';
        public string $failureCode = '';
        public function __construct(private Closure $recordSignals) {}

        public function connect($host, $port = null, $timeout = 30, $options = []) {
            $this->stage = 'connection';
            return parent::connect($host, $port, $timeout, $options);
        }

        public function hello($host = '') {
            $this->stage = 'greeting';
            return parent::hello($host);
        }

        public function startTLS() {
            $this->stage = 'tls';
            return parent::startTLS();
        }

        public function authenticate($username, $password, $authtype = null, $OAuth = null) {
            $this->stage = 'authentication';
            return parent::authenticate($username, $password, $authtype, $OAuth);
        }

        protected function setError($message, $detail = '', $smtp_code = '', $smtp_code_ex = '') {
            ($this->recordSignals)((string) $message . ' ' . (string) $detail);
            // Guarda somente código numérico; close()/QUIT podem apagar o erro original.
            if ($message !== '' && preg_match('/^[45][0-9]{2}$/D', (string) $smtp_code)) {
                $this->failureCode = (string) $smtp_code;
            }
            parent::setError($message, $detail, $smtp_code, $smtp_code_ex);
        }
    };
    $probe->Timelimit = 10;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->setSMTPInstance($probe);
    $mail->Host = $smtp['host'];
    $mail->Port = (int) $smtp['port'];
    $mail->SMTPSecure = $smtp['encryption'];
    $mail->SMTPAuth = true;
    $mail->Username = $smtp['username'];
    $mail->Password = $smtp['password'];
    $mail->Timeout = 5;
    $mail->SMTPDebug = 0;
    // Preserva a validação padrão do certificado; não usa send(), MAIL, RCPT ou DATA.
    if (!$mail->smtpConnect()) {
        throw new RuntimeException('Connection check failed.');
    }
    $result['status'] = 'SUCCESS';
    $result['stage'] = 'authentication';
    $result['reason'] = 'SMTP_CONNECTION_AND_AUTH_OK';
    $exitCode = 0;
} catch (Throwable $error) {
    $recordSignals($error->getMessage());
    $result['stage'] = $probe !== null ? $probe->stage : 'configuration';
    $result['reason'] ??= match ($result['stage']) {
        'authentication' => 'SMTP_AUTH_FAILED',
        'tls' => 'SMTP_TLS_FAILED',
        'greeting' => 'SMTP_GREETING_FAILED',
        'connection' => 'SMTP_CONNECTION_FAILED',
        default => 'APP_CONFIGURATION_OR_RUNTIME_FAILED',
    };
    if ($probe !== null && $probe->failureCode !== '') {
        $result['smtp_code'] = $probe->failureCode;
    }
    // Não imprime mensagens brutas de exceções, respostas SMTP ou stack traces.
} finally {
    if ($mail !== null) {
        $mail->smtpClose();
    }
    restore_error_handler();
}

$result['signals'] = array_keys($signals);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit($exitCode);
