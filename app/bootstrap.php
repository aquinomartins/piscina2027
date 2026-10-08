<?php
declare(strict_types=1);
define('PISCINA_APP_ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $prefix = 'Piscina\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($path)) require $path;
    } elseif (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        require dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/' . substr($class, 20) . '.php';
    }
});
function config(): array {
    static $config;
    if ($config !== null) return $config;
    $path = getenv('PISCINA_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
    if (!is_file($path)) throw new RuntimeException('Configuração privada ausente. Consulte o guia de instalação.');
    $config = require $path;
    if (!is_array($config) || !isset($config['database'], $config['storage'], $config['url'])) throw new RuntimeException('Configuração incompleta.');
    if(!in_array($config['environment']??'', ['production','demo'],true))throw new RuntimeException('Ambiente deve ser production ou demo explícito.');
    $base = $config['base_path'] ?? '';
    if ($base !== '' && (!preg_match('~^/[a-zA-Z0-9/_-]+$~D', $base) || str_ends_with($base, '/'))) throw new RuntimeException('Caminho-base inválido.');
    foreach (['pdo_mysql','bcmath','gd','mbstring','fileinfo','openssl'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException('Extensão PHP necessária indisponível: ' . $ext);
    if (PHP_INT_SIZE !== 8 || PHP_VERSION_ID < 80300) throw new RuntimeException('PHP 8.3+ de 64 bits necessário.');
    if (($config['environment'] ?? '') === 'production' && (!str_starts_with($config['url'], 'https://') || !($config['secure_cookie'] ?? false))) throw new RuntimeException('Produção exige HTTPS e cookie Secure.');
    date_default_timezone_set('UTC');
    return $config;
}
function db(): Piscina\Database { static $db; return $db ??= new Piscina\Database(config()['database']); }
function service(): Piscina\Domain { static $service; return $service ??= new Piscina\Domain(db(), config()); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return (config()['base_path'] ?? '') . '/' . ltrim($path, '/'); }
function formatAmount(string $value,string $asset='MR'): string {
    $precision=$asset==='MR'?2:($asset==='BYC'?8:0);$negative=str_starts_with($value,'-');$digits=str_pad(ltrim($value,'-'),$precision+1,'0',STR_PAD_LEFT);
    $whole=$precision?substr($digits,0,-$precision):$digits;$fraction=$precision?substr($digits,-$precision):'';if($asset==='BYC')$fraction=rtrim($fraction,'0');
    $whole=preg_replace('/\B(?=(\d{3})+(?!\d))/','.',$whole);
    return ($asset==='MR'?'mR$ ':'').($negative?'-':'').$whole.($fraction!==''?','.$fraction:'').($asset==='BYC'?' BYC':($asset==='SHARE'?' cotas':''));
}
