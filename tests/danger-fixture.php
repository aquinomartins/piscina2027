<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn'])||!str_ends_with($argv[2]??'','@local.invalid'))throw new RuntimeException('Somente fixture local de recuperação no banco piscina_test em demo.');
$email=$argv[2];
if($argv[1]==='expire-reset'){
    db()->run("UPDATE auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE user_id=(SELECT id FROM users WHERE email=?) AND kind='reset'",[$email]);exit;
}
if($argv[1]!=='export-reset')exit(1);
$r=db()->row("SELECT o.body FROM outbox o JOIN users u ON u.id=o.user_id WHERE u.email=? AND o.subject='Redefina sua senha' ORDER BY o.id DESC LIMIT 1",[$email]);
if(!$r||!preg_match('/#reset=([a-f0-9]{64})/',$r['body'],$m))throw new RuntimeException('Token de teste não encontrado.');
$t=db()->row("SELECT used_at FROM auth_tokens WHERE token_hash=? AND kind='reset'",[hash('sha256',$m[1])]);
echo json_encode(['token'=>$m[1],'used_at'=>$t['used_at']]);
