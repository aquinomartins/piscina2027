<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    $c=config();$settings=$c['http_tasks']??[];
    if(!($settings['enabled']??false)||empty($settings['token_hash']))throw new Piscina\Problem('Endpoint fechado.',404);
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new Piscina\Problem('Método não permitido.',405);
    if($c['environment']==='production'&&(!isset($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'))throw new Piscina\Problem('HTTPS obrigatório.',403);
    $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([A-Za-z0-9_-]{43,128})$/D',$auth,$m)||!hash_equals($settings['token_hash'],hash('sha256',$m[1])))throw new Piscina\Problem('Credencial inválida.',403);
    $nonce=$_SERVER['HTTP_X_TASK_NONCE']??'';$timestamp=$_SERVER['HTTP_X_TASK_TIMESTAMP']??'';
    if(!preg_match('/^[A-Za-z0-9_-]{32,80}$/D',$nonce)||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs(time()-(int)$timestamp)>90)throw new Piscina\Problem('Cabeçalhos de execução inválidos ou vencidos.',409);
    try{db()->run('INSERT INTO task_requests(nonce_hash,created_at) VALUES(?,UTC_TIMESTAMP(6))',[hash('sha256',$nonce)]);}catch(PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new Piscina\Problem('Repetição de execução recusada.',409);throw $e;}
    echo json_encode(['ok'=>true,'data'=>(new Piscina\Tasks(service(),$c))->run()],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code($e instanceof Piscina\Problem?$e->status:503);echo json_encode(['ok'=>false,'message'=>$e instanceof Piscina\Problem?$e->getMessage():'Falha de tarefa. Consulte log privado.'],JSON_UNESCAPED_UNICODE);if(!$e instanceof Piscina\Problem)error_log('Tarefa HTTP: '.$e->getMessage());}
