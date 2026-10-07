<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$requestId=bin2hex(random_bytes(8));
try {
    $config=config();$db=db();$auth=new Piscina\Auth($db,$config);$auth->session();$domain=service();$queries=new Piscina\Queries($domain);
    $method=$_SERVER['REQUEST_METHOD'];$action=(string)($_GET['action']??'meta');
    if($method==='GET') {
        $result=match($action) {
            'meta'=>(function() use($auth,$domain,$config){$u=null;try{$u=$domain->user($auth->actor());}catch(Piscina\Problem $e){if($e->status!==401) throw $e;}return ['csrf'=>$auth->csrf(),'user'=>$u,'environment'=>$config['environment'],'base_path'=>$config['base_path'],'policy_active'=>$domain->policy()!==null,'policy'=>require PISCINA_APP_ROOT.'/config/policy.php'];})(),
            'market'=>$queries->market($_GET),'nft'=>$queries->nft(Piscina\Domain::id($_GET['id']??null)),
            'profile'=>$queries->profile(Piscina\Domain::id($_GET['id']??null)),
            'dashboard'=>$queries->dashboard($auth->actor(),$_GET),'proposal'=>$queries->proposal($auth->actor(),Piscina\Domain::id($_GET['id']??null)),
            'quote'=>$domain->quote($auth->actor(),Piscina\Domain::id($_GET['nft_id']??null)),
            'operation'=>$queries->operation($auth->actor(),$_GET),'admin'=>$queries->admin($auth->actor(),$_GET),
            default=>throw new Piscina\Problem('Endpoint desconhecido.',404)
        };
    } elseif($method==='POST') {
        $auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['csrf']??null));
        if((int)($_SERVER['CONTENT_LENGTH']??0)>($config['upload_bytes']??5242880)+65536) throw new Piscina\Problem('Requisição excede o limite.',413);
        $data=$action==='nft.upload'?$_POST:json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)) throw new Piscina\Problem('Corpo JSON inválido.');
        $result=match($action) {
            'auth.register'=>$auth->register($data),'auth.login'=>$auth->login($data),'auth.logout'=>$auth->logout(),
            'auth.request_verify'=>$auth->requestToken($data,'verify'),'auth.request_reset'=>$auth->requestToken($data,'reset'),
            'auth.verify'=>$auth->useToken($data,'verify'),'auth.reset'=>$auth->useToken($data,'reset'),
            'nft.upload'=>(new Piscina\Upload($domain,$config))->save($auth->actor(),Piscina\Domain::id($data['nft_id']??null),$_FILES['image']??[],$_SERVER['HTTP_IDEMPOTENCY_KEY']??''),
            'admin.tasks'=>(function() use($auth,$domain,$config,$data){$actor=$auth->actor();$domain->admin($actor);$auth->reauthenticate($actor,(string)($data['password']??''));session_write_close();return (new Piscina\Tasks($domain,$config))->run();})(),
            default=>(function() use($auth,$domain,$action,$data){
                if($action==='nft.image') throw new Piscina\Problem('Use o upload autorizado.',404);
                $actor=$auth->actor();if(str_starts_with($action,'admin.')) {$domain->admin($actor);$auth->reauthenticate($actor,(string)($data['password']??''));}
                $payload=$data;unset($payload['password']);$key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';session_write_close();return $domain->operation($actor,$action,$payload,$key);
            })()
        };
    } else throw new Piscina\Problem('Método não permitido.',405);
    echo json_encode(['ok'=>true,'data'=>$result,'request_id'=>$requestId],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    $status=$e instanceof Piscina\Problem?$e->status:($e instanceof JsonException?400:503);http_response_code($status);
    if($status>=500) {error_log('Piscina '.$requestId.' '.$e::class.': '.$e->getMessage());}
    echo json_encode(['ok'=>false,'error'=>$e instanceof Piscina\Problem?$e->error:'service_unavailable','message'=>$e instanceof Piscina\Problem?$e->getMessage():($status===400?'JSON inválido.':'Serviço indisponível. Verifique a configuração privada e use o identificador no suporte.'),'request_id'=>$requestId],JSON_UNESCAPED_UNICODE);
}
