<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store');header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new Piscina\Problem('Método não permitido.',405);
    $auth=new Piscina\Auth(db(),config());$auth->checkCsrf($_POST['csrf']??null);$action=$_POST['action']??'';
    $result=match($action){'auth.login'=>$auth->login($_POST),'auth.register'=>$auth->register($_POST),'auth.logout'=>$auth->logout(),default=>throw new Piscina\Problem('Ação indisponível.',404)};
    header('Location: '.url('index.php?view=dashboard'),true,303);
} catch(Throwable $e) {http_response_code($e instanceof Piscina\Problem?$e->status:503);echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Operação</title><p>'.h($e instanceof Piscina\Problem?$e->getMessage():'Serviço indisponível.').'</p><a href="'.h(url('index.php?view=dashboard')).'">Voltar</a></html>';}
