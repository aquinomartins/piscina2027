<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$reference=bin2hex(random_bytes(8));
try{
    $d=service();$auth=new Piscina\Auth(db(),config());$auth->session();$actor=$auth->actor();$d->admin($actor);$service=new Piscina\PaymentTests($d);$action=(string)($_GET['action']??'list');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(!in_array($action,['list','read','bootstrap','lookup'],true))throw new Piscina\Problem('Método não permitido.',405);
        session_write_close();$result=match($action){'bootstrap'=>$service->bootstrap($actor),'list'=>$service->list($actor,$_GET),'read'=>$service->read($actor,(string)($_GET['id']??'')),'lookup'=>$service->lookup($actor,(string)($_GET['key']??''))};
    }elseif($_SERVER['REQUEST_METHOD']==='POST'){
        $auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??null);if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new Piscina\Problem('Requisição excede o limite.',413);
        $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new Piscina\Problem('Requisição excede o limite.',413);$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new Piscina\Problem('Corpo JSON inválido.');
        if(in_array($action,['create','cancel','abandon'],true)){Piscina\PaymentTestAuth::check($auth,$d,$actor,$data['username']??null,$data['password']??null);unset($data['username'],$data['password']);}
        session_write_close();
        $result=match($action){
            'create'=>$service->create($actor,$data,(string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??'')),
            'refresh'=>(function()use($service,$actor,$data){if(array_keys($data)!==['id'])throw new Piscina\Problem('Campos não permitidos.');return $service->refresh($actor,(string)$data['id']);})(),
            'cancel'=>(function()use($service,$actor,$data){if(array_diff(array_keys($data),['id','confirm'])||($data['confirm']??false)!==true)throw new Piscina\Problem('Confirme o cancelamento do teste.');return $service->cancel($actor,(string)($data['id']??''));})(),
            'abandon'=>(function()use($service,$actor,$data){if(array_diff(array_keys($data),['key','confirm'])||($data['confirm']??false)!==true)throw new Piscina\Problem('Confirme o cancelamento da tentativa.');return $service->abandon($actor,(string)($data['key']??''));})(),
            default=>throw new Piscina\Problem('Ação desconhecida.',404)
        };
    }else throw new Piscina\Problem('Método não permitido.',405);
    echo json_encode(['ok'=>true,'data'=>$result,'request_id'=>$reference],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    $status=$e instanceof Piscina\Problem?$e->status:($e instanceof JsonException?400:503);http_response_code($status);
    if($status>=500)error_log('Piscina PagamentosTeste '.$reference.' '.$e::class);
    echo json_encode(['ok'=>false,'error'=>$e instanceof Piscina\Problem?$e->error:'test_unavailable','message'=>$e instanceof Piscina\Problem?$e->getMessage():'Não foi possível confirmar o teste. Consulte o resultado antes de iniciar outra operação.','request_id'=>$reference],JSON_UNESCAPED_UNICODE);
}
