<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Somente testes descartáveis.');
// HTTP falso exclusivamente no processo de teste; não há seletor de mock no runtime distribuído.
final class FakeMercadoPagoTestHttp implements Piscina\PaymentTestTransport {
    public array $credential=['access_token'=>'TEST-12345-111712-0123456789abcdef0123456789abcdef-23456','public_key'=>'TEST-12345678-1234-4321-9876-123456789abc','token_type'=>'bearer','user_id'=>'23456','live_mode'=>false,'expires_in'=>'3600'];
    public array $userOverride=[],$customerOverride=[],$customerGetOverride=[],$customers=[];public ?array $searchOverride=null;public bool $lostCustomer=false,$rejectCustomer=false,$rejectIdentity=false;public int $userQueries=0,$customerCreates=0,$customerQueries=0,$customerSearches=0;
    public array $payments=[],$posts=[],$overrides=[],$cardOverride=[];public string $nextStatus='approved';public ?string $getStatus=null;public bool $lostAfterPost=false,$lostBeforePost=false,$rejectPost=false,$rejectGet=false;public int $creates=0,$queries=0,$oauth=0;public static int $next=10000;public ?Closure $hook=null;
    public function request(string $method,string $path,?string $body=null,array $headers=[]): array {
        if($path==='/oauth/token'){$this->oauth++;$d=json_decode($body,true);check(($d['test_token']??null)===true,'OAuth sempre solicita teste.');assertSame('client_credentials',$d['grant_type']);return $this->credential;}
        if(!in_array('Authorization: Bearer '.$this->credential['access_token'],$headers,true))throw new Piscina\Problem('Credencial recusada pelo transporte local.',422,'test_provider_rejected');
        if($path==='/users/me'){$this->userQueries++;if($this->rejectIdentity)throw new Piscina\Problem('Identidade indisponível no transporte local.',503,'test_provider_unavailable');return $this->userOverride+['id'=>'23456','site_id'=>'MLB'];}
        if(str_starts_with($path,'/v1/customers/search?')){$this->customerSearches++;parse_str(substr($path,strpos($path,'?')+1),$q);return $this->searchOverride??['results'=>array_values(array_filter($this->customers,fn($c)=>$c['email']===$q['email']))];}
        if($path==='/v1/customers'&&$method==='POST'){
            $this->customerCreates++;if($this->rejectCustomer)throw new Piscina\Problem('Clientes indisponível no transporte local.',422,'test_provider_rejected');$d=json_decode($body,true);check(array_keys($d)===['email','description'],'Prova não envia cartão ou dados pessoais.');check((bool)preg_match('/^test_payer_[0-9]{1,10}@testuser\.com$/D',$d['email']),'E-mail técnico fictício.');
            $c=$this->customerOverride+['id'=>'23456-test-customer','email'=>$d['email'],'live_mode'=>false];$this->customers[$c['id']]=$c;if($this->lostCustomer){$this->lostCustomer=false;throw new Piscina\Problem('Resposta do cadastro técnico perdida.',503,'test_provider_unavailable');}return $c;
        }
        if(preg_match('~^/v1/customers/([A-Za-z0-9_-]+)$~D',$path,$m)){$this->customerQueries++;return $this->customerGetOverride+($this->customers[$m[1]]??[]);}
        if($path==='/v1/payment_methods'){if($this->hook){$f=$this->hook;$this->hook=null;$f();}return [['id'=>'master','payment_type_id'=>'credit_card','status'=>'active'],['id'=>'elo_debito','payment_type_id'=>'debit_card','status'=>'active'],['id'=>'pix','payment_type_id'=>'bank_transfer','status'=>'active']];}
        if(str_starts_with($path,'/v1/card_tokens/')){$id=substr($path,16);$debit=str_contains($id,'debit');return $this->cardOverride+['id'=>$id,'live_mode'=>false,'public_key'=>$this->credential['public_key'],'first_six_digits'=>$debit?'506776':'548083','last_four_digits'=>$debit?'8311':'3311'];}
        if($method==='POST'&&$path==='/v1/payments'){
            $this->creates++;$p=Piscina\MercadoPagoTestHttp::decode($body);$this->posts[]=$p;check((bool)array_filter($headers,fn($h)=>str_starts_with($h,'X-Idempotency-Key: piscina-test-')),'Chave enviada ao provedor.');
            if($this->lostBeforePost)throw new Piscina\Problem('Falha local injetada.',503,'test_provider_unavailable');if($this->rejectPost)throw new Piscina\Problem('Solicitação recusada pelo provedor local de teste.',422,'test_provider_rejected');$id=(string)++self::$next;
            $r=$this->overrides+['id'=>$id,'live_mode'=>false,'status'=>$this->nextStatus,'captured'=>true,'transaction_amount'=>$p['transaction_amount'],'transaction_amount_refunded'=>'0','currency_id'=>'BRL','collector_id'=>'23456','external_reference'=>$p['external_reference'],'metadata'=>$p['metadata'],'payment_method_id'=>$p['payment_method_id'],'payment_type_id'=>$p['payment_method_id']==='pix'?'bank_transfer':($p['payment_method_id']==='master'?'credit_card':'debit_card'),'date_last_updated'=>'2026-11-17T12:00:00.000Z'];
            $this->payments[$id]=$r;if($this->lostAfterPost){$this->lostAfterPost=false;throw new Piscina\Problem('Resposta perdida.',503,'test_provider_unavailable');}return $r;
        }
        if(str_starts_with($path,'/v1/payments/search?')){parse_str(substr($path,strpos($path,'?')+1),$q);return ['results'=>array_values(array_filter($this->payments,fn($p)=>$p['external_reference']===$q['external_reference']))];}
        if(preg_match('~^/v1/payments/([0-9]+)$~D',$path,$m)){
            if(!isset($this->payments[$m[1]]))throw new Piscina\Problem('Não encontrado.',503,'test_provider_unavailable');
            if($method==='PUT'){$this->payments[$m[1]]['status']='cancelled';return $this->payments[$m[1]];}
            $this->queries++;if($this->rejectGet)throw new Piscina\Problem('Consulta temporariamente não autorizada no transporte local.',422,'test_provider_rejected');$r=$this->payments[$m[1]];if($this->getStatus!==null)$r['status']=$this->getStatus;return $r;
        }
        throw new RuntimeException('Chamada inesperada no transporte de teste.');
    }
}
function ptfixture(array $overrides=[]): array {
    $http=new FakeMercadoPagoTestHttp();$http->credential['access_token']='TEST-12345-111712-'.bin2hex(random_bytes(16)).'-23456';
    $cfg=service()->config;$cfg['payments_test']=$overrides+['enabled'=>true,'mode'=>'test','credential_source'=>'test_credentials','public_key'=>$http->credential['public_key'],'access_token'=>$http->credential['access_token'],'client_id'=>'12345','client_secret'=>bin2hex(random_bytes(16)),'collector_id'=>'23456','max_amount'=>'1000.00','webhook_secret'=>str_repeat('s',32),'payer_email'=>'pagador.teste@example.com'];service()->config=$cfg;
    $provider=new Piscina\MercadoPagoSandbox($cfg,$http);$payments=new Piscina\PaymentTests(service(),$provider);return [$payments,$http,$provider];
}
function ptdata(string $method='credit_card',string $dest='wallet',string $amount='100.01'): array {
    return ['amount'=>$amount,'destination'=>$dest,'method'=>$method,'card'=>$method==='pix'?[]:['token'=>'fake_'.$method.'_'.str_repeat('a',24),'payment_method_id'=>$method==='credit_card'?'master':'elo_debito','installments'=>'1','payer'=>['email'=>'pagador.teste@example.com','identification'=>['type'=>'CPF','number'=>'12345678909']]],'confirm'=>true];
}
function ptcreditCount(): int {return (int)db()->row("SELECT COUNT(*) n FROM ledger_transactions WHERE kind='PAYMENT_TEST_CREDIT'")['n'];}
function ptstage(Piscina\PaymentTests $p,FakeMercadoPagoTestHttp $h): array {
    $h->nextStatus='pending';$r=$p->create(1,ptdata(),testKey());$h->payments[$r['provider_id']]['status']='approved';
    db()->pdo->exec("CREATE TRIGGER fail_test_credit BEFORE INSERT ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha injetada no crédito experimental'");
    try{try{$p->refresh(1,$r['id']);throw new RuntimeException('Crédito deveria falhar.');}catch(PDOException $e){check(str_contains($e->getMessage(),'Falha injetada'),'Trigger exercitado.');}}finally{db()->pdo->exec('DROP TRIGGER fail_test_credit');}
    return $p->read(1,$r['id']);
}
function ptconcurrent(string $id): array {
    $dir=config()['storage'].'/tmp';$barrier=$dir.'/'.testKey().'.go';$procs=[];
    for($i=0;$i<2;$i++){$file=$dir.'/'.testKey().'.json';file_put_contents($file,json_encode(['id'=>$id,'barrier'=>$barrier,'key'=>testKey()]));$pipes=[];$proc=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/payment-test-worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$procs[]=[$proc,$pipes,$file];}
    $until=microtime(true)+10;foreach($procs as [,, $f])while(!is_file($f.'.ready')){if(microtime(true)>$until)throw new RuntimeException('Worker não iniciou.');usleep(10000);}touch($barrier);$out=[];
    foreach($procs as [$proc,$pipes,$f]){$raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($error);$out[]=json_decode($raw,true,32,JSON_THROW_ON_ERROR);unlink($f);unlink($f.'.ready');}unlink($barrier);return $out;
}
