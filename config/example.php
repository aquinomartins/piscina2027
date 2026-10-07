<?php
return [
    'environment' => 'production', // production ou demo explícito; nunca fallback
    'url' => 'https://SEU-DOMINIO',
    'base_path' => '', // '/piscina' se instalado em subpasta
    'timezone' => 'America/Sao_Paulo',
    'database' => ['dsn' => 'mysql:host=localhost;dbname=SEU_BANCO;charset=utf8mb4', 'user' => '', 'password' => ''],
    'storage' => dirname(__DIR__) . '/storage',
    'session_path' => dirname(__DIR__) . '/storage/sessions',
    'secure_cookie' => true,
    'smtp' => ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls', 'from' => '', 'name' => 'Piscina de Liquidez'],
    'mail_transport' => 'smtp', // 'file' permitido somente em demo
    'max_users' => 500, 'max_nfts' => 5000, 'snapshot_periods_per_run' => 10, 'upload_bytes' => 5242880, 'upload_pixels' => 16000000,
    'max_batch' => 100, 'cron_seconds' => 50,
    'http_tasks' => ['enabled' => false, 'token_hash' => ''], // bearer aleatório, somente hash privado
    'installation' => ['enabled' => false, 'token_hash' => '', 'expires_at' => ''], // sha256 de segredo temporário externo
];
