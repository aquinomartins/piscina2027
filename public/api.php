<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$requestId=bin2hex(random_bytes(8));
try {
    $config=config();$db=db();$auth=new Piscina\Auth($db,$config);$auth->session();$domain=service();$queries=new Piscina\Queries($domain);
    $galeria=null;$viewer=function()use($auth){try{return $auth->actor();}catch(Piscina\Problem $e){if($e->status===401)return null;throw $e;}};
    $method=$_SERVER['REQUEST_METHOD'];$action=(string)($_GET['action']??'meta');
    // Clientes antigos enviavam action=operation&action=acao. Preserve a consulta
    // do recibo durante a atualização explícita do PWA, sem executar uma mutação.
    if($method==='GET'&&isset($_GET['key'])&&!isset($_GET['operation_action'])){
        preg_match_all('/(?:^|&)action=([^&]*)/',$_SERVER['QUERY_STRING']??'',$legacy);
        if(count($legacy[1])===2&&rawurldecode($legacy[1][0])==='operation'){$_GET['operation_action']=$action;$action='operation';}
    }
    if($method==='GET') {
        if($action==='meta'||$action==='admin.galeria'||str_starts_with($action,'galeria.'))$galeria=new Piscina\GaleriaQueries($domain);
        $result=match($action) {
            'meta'=>(function() use($auth,$domain,$config,$galeria){$u=null;try{$u=$domain->user($auth->actor());}catch(Piscina\Problem $e){if($e->status!==401) throw $e;}return ['csrf'=>$auth->csrf(),'user'=>$u,'environment'=>$config['environment'],'base_path'=>$config['base_path'],'policy_active'=>$domain->policy()!==null,'galeria'=>$galeria->status(),'policy'=>require PISCINA_APP_ROOT.'/config/policy.php'];})(),
            'galeria.status'=>$galeria->status(),'galeria.catalog'=>$galeria->catalog($_GET),
            'galeria.frame'=>$galeria->frame(Piscina\Domain::id($_GET['id']??null),$viewer(),$_GET),
            'galeria.mine'=>$galeria->mine($auth->actor(),$_GET),
            'galeria.trade.proposal'=>(new Piscina\MolduraTrades(new Piscina\Galeria($domain)))->read($auth->actor(),Piscina\Domain::id($_GET['id']??null)),
            'galeria.proposal'=>$galeria->proposal($auth->actor(),Piscina\Domain::id($_GET['id']??null)),
            'galeria.events'=>$galeria->events($_GET),
            'galeria.event'=>$galeria->event(Piscina\Domain::id($_GET['id']??null),$viewer(),$_GET),
            'galeria.ranking'=>(new Piscina\GaleriaRanking($db))->read($_GET),
            'admin.galeria'=>$galeria->admin($auth->actor(),$_GET),
            'market'=>$queries->market($_GET),'nft'=>$queries->nft(Piscina\Domain::id($_GET['id']??null)),
            'profile'=>$queries->profile(Piscina\Domain::id($_GET['id']??null)),
            'dashboard'=>$queries->dashboard($auth->actor(),$_GET),'proposal'=>$queries->proposal($auth->actor(),Piscina\Domain::id($_GET['id']??null)),
            'quote'=>$domain->quote($auth->actor(),Piscina\Domain::id($_GET['nft_id']??null)),
            'operation'=>$queries->operation($auth->actor(),array_replace($_GET,['action'=>$_GET['operation_action']??''])),'admin'=>$queries->admin($auth->actor(),$_GET),
            'admin.wallet'=>$queries->adminWallet($auth->actor(),$_GET),
            'admin.nft'=>$queries->adminNft($auth->actor(),$_GET),
            'admin.nft.image_result'=>$queries->adminImageResult($auth->actor(),$_GET),
            'admin.danger'=>(new Piscina\AdminDanger($domain))->view($auth->actor(),Piscina\Domain::id($_GET['user_id']??null),(int)($_GET['offset']??0)),
            'admin.danger.result'=>(function()use($auth,$domain,$queries){$actor=$auth->actor();$domain->admin($actor);$a=$_GET['operation_action']??'';if(!in_array($a,['admin.danger.fine','admin.danger.ban','admin.danger.unban','admin.danger.delete','admin.danger.password'],true))throw new Piscina\Problem('Ação indisponível.',404);return $queries->operation($actor,['action'=>$a,'key'=>$_GET['key']??'']);})(),
            default=>throw new Piscina\Problem('Endpoint desconhecido.',404)
        };
    } elseif($method==='POST') {
        $auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['csrf']??null));
        if((int)($_SERVER['CONTENT_LENGTH']??0)>($config['upload_bytes']??5242880)+65536) throw new Piscina\Problem('Requisição excede o limite.',413);
        $data=in_array($action,['nft.upload','admin.nft.image_upload','admin.galeria.frame.photo_upload'],true)?$_POST:json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)) throw new Piscina\Problem('Corpo JSON inválido.');
        $result=match($action) {
            'auth.register'=>$auth->register($data),'auth.login'=>$auth->login($data),'auth.logout'=>$auth->logout(),
            'galeria.preview'=>(function()use($auth,$domain,$data){$actor=$auth->actor();session_write_close();return $domain->db->transaction(function()use($domain,$actor,$data){$domain->db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return (new Piscina\Coalizoes(new Piscina\Galeria($domain)))->preview($actor,$data);});})(),
            'galeria.trade.preview'=>(function()use($auth,$domain,$data){$actor=$auth->actor();session_write_close();return $domain->db->transaction(function()use($domain,$actor,$data){$domain->db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return (new Piscina\MolduraTrades(new Piscina\Galeria($domain)))->preview($actor,$data);});})(),
            'auth.request_verify'=>$auth->requestToken($data,'verify'),'auth.request_reset'=>$auth->requestToken($data,'reset'),
            'auth.verify'=>$auth->useToken($data,'verify'),'auth.reset'=>$auth->useToken($data,'reset'),
            'admin.danger.password'=>(function()use($auth,$domain,$data){$actor=$auth->actor();$domain->admin($actor);$auth->reauthenticate($actor,(string)($data['password']??''));session_write_close();return (new Piscina\AdminCredentials($domain))->reset($actor,$data,$_SERVER['HTTP_IDEMPOTENCY_KEY']??'');})(),
            'nft.upload'=>(new Piscina\Upload($domain,$config))->save($auth->actor(),Piscina\Domain::id($data['nft_id']??null),$_FILES['image']??[],$_SERVER['HTTP_IDEMPOTENCY_KEY']??''),
            'admin.nft.image_upload'=>(function()use($auth,$domain,$config,$data){
                $actor=$auth->actor();$domain->admin($actor);$auth->reauthenticate($actor,(string)($data['password']??''));
                $payload=['reason'=>$data['reason']??null,'expected_version'=>$data['expected_version']??null,'confirm'=>($data['confirm']??'')==='1'];
                session_write_close();
                return (new Piscina\AdminImageUpload($domain,$config))->save($actor,Piscina\Domain::id($data['nft_id']??null),$_FILES['image']??[],$_SERVER['HTTP_IDEMPOTENCY_KEY']??'',$payload);
            })(),
            'admin.galeria.frame.photo_upload'=>(function()use($auth,$domain,$data){$actor=$auth->actor();Piscina\GaleriaAdminAuth::check($auth,$domain,$actor,(string)($data['password']??''));session_write_close();return (new Piscina\GaleriaMedia($domain))->save($actor,Piscina\Domain::id($data['frame_id']??null),$_FILES['image']??[],['confirm'=>($data['confirm']??'')==='1','reason'=>$data['reason']??null],$_SERVER['HTTP_IDEMPOTENCY_KEY']??'');})(),
            'admin.tasks'=>(function() use($auth,$domain,$config,$data){$actor=$auth->actor();$domain->admin($actor);$auth->reauthenticate($actor,(string)($data['password']??''));session_write_close();return (new Piscina\Tasks($domain,$config))->run();})(),
            default=>(function() use($auth,$domain,$action,$data){
                if(in_array($action,['nft.image','admin.nft.image_replace','admin.galeria.frame.photo'],true)) throw new Piscina\Problem('Use o upload autorizado.',404);
                $actor=$auth->actor();if(str_starts_with($action,'admin.')) {$domain->admin($actor);if(str_starts_with($action,'admin.galeria.'))Piscina\GaleriaAdminAuth::check($auth,$domain,$actor,(string)($data['password']??''));else $auth->reauthenticate($actor,(string)($data['password']??''));}
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
