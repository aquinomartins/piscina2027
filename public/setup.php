<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
try {
    $c=config();$i=$c['installation']??[];$lock=$c['storage'].'/install.lock';
    if(!($i['enabled']??false)||is_file($lock)||empty($i['token_hash'])||empty($i['expires_at'])||new DateTimeImmutable($i['expires_at'])<new DateTimeImmutable())throw new Piscina\Problem('Instalador fechado.',404);
    if($c['environment']==='production'&&(!isset($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'))throw new Piscina\Problem('Instalação exige HTTPS.',403);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $token=(string)($_POST['token']??'');if(strlen($token)<32||!hash_equals($i['token_hash'],hash('sha256',$token)))throw new Piscina\Problem('Credencial temporária inválida.',403);
        $installer=new Piscina\Installer(db(),$c);$installer->migrate();
        $file=fopen($lock,'x');if(!$file)throw new Piscina\Problem('Instalação em andamento ou já fechada.',409);
        try{$id=$installer->admin((string)($_POST['email']??''),(string)($_POST['name']??''),(string)($_POST['password']??''));fwrite($file,"INSTALAÇÃO FECHADA\n".gmdate('c'));fclose($file);chmod($lock,0600);echo '<p>Administrador criado. Instalador fechado. Desative installation.enabled e remova setup.php.</p><a href="'.h(url('index.php?view=admin')).'">Entrar na administração</a>';}catch(Throwable $e){fclose($file);throw $e;}exit;
    }
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação protegida</title><link rel="stylesheet" href="'.h(url('assets/app.css')).'"><main class="auth-panel"><h1>Instalação protegida</h1><form method="post"><label>Segredo temporário<input type="password" name="token" required autocomplete="off"></label><label>E-mail do administrador<input type="email" name="email" required></label><label>Nome público<input name="name" required></label><label>Senha do administrador<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><button class="primary">Instalar e fechar instalador</button></form></main></html>';
} catch(Throwable $e){http_response_code($e instanceof Piscina\Problem?$e->status:503);echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><p>'.h($e instanceof Piscina\Problem?$e->getMessage():'Falha de instalação. Verifique o log privado.').'</p></html>';error_log('Instalação: '.$e->getMessage());}
