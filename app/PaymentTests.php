<?php
declare(strict_types=1);
namespace Piscina;

// Orquestra a API fora de transações econômicas. Só a consulta autenticada autoriza o crédito.
final class PaymentTests {
    private MercadoPagoSandbox $provider;
    public function __construct(private Domain $d,?MercadoPagoSandbox $provider=null){$this->provider=$provider??new MercadoPagoSandbox($d->config);}
    public static function installed(Database $db): bool {return (bool)$db->row("SELECT version FROM schema_migrations WHERE version='007_pagamentos_teste'");}
    public function enabled(): void {
        if(!self::installed($this->d->db))throw new Problem('Instale a migração 007 para acessar pagamentos de teste.',409,'test_migration_missing');
        $c=$this->d->config['payments_test']??[];
        if(($c['enabled']??false)!==true||($c['mode']??'')!=='test')throw new Problem('Pagamentos de teste desativados na configuração privada.',409,'test_disabled');
        if(($this->d->policy()['assets']??'')!=='internal'||($this->d->policy()['payment']??'')!=='MR')throw new Problem('Este módulo exige o livro de moeda exclusivamente simulada da Piscina.',409,'test_virtual_money_required');
    }
    public function bootstrap(int $actor): array {
        $u=$this->d->admin($actor);$out=['enabled'=>false,'admin_id'=>$u['id'],'username'=>$u['email'],'reason'=>null,'experimental'=>true,'currency'=>'BRL','credit_asset'=>'MR'];
        try{$this->enabled();$max=$this->max();$c=$this->provider->credentials();$methods=$this->provider->methods();$out+=['public_key'=>$c['public_key'],'methods'=>['credit_card'=>in_array('credit_card',$methods,true),'debit_card'=>in_array('debit_card',$methods,true),'pix'=>($methods['pix']??'')==='bank_transfer']];$out['max_amount']=$max;$out['enabled']=true;}
        catch(Problem $e){$out['reason']=$e->getMessage();}return $out;
    }
    private function max(): string {return Exact::units($this->d->config['payments_test']['max_amount']??'1000.00',2);}
    public static function key(string $key): void {if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new Problem('Chave de idempotência inválida.');}
    private function input(array $data): array {
        if(array_diff(array_keys($data),['amount','destination','method','card','confirm']))throw new Problem('Campos não permitidos.');
        if(($data['confirm']??false)!==true)throw new Problem('Revise o resumo e confirme o pagamento de teste.');
        $amount=Exact::units($data['amount']??null,2);if(bccomp($amount,$this->max(),0)>0)throw new Problem('O valor excede o limite técnico configurado para testes.');
        $destination=$data['destination']??'';$method=$data['method']??'';
        if(!in_array($destination,['wallet','treasury','galeria_revenue'],true)||!in_array($method,['pix','credit_card','debit_card'],true))throw new Problem('Escolha um destino interno e um meio de teste válido.');
        $card=$data['card']??[];if(!is_array($card))throw new Problem('Dados tokenizados inválidos.');
        if(array_diff(array_keys($card),['token','payment_method_id','issuer_id','installments','payer']))throw new Problem('Dados de cartão não são aceitos pela aplicação. Use somente o componente seguro.');
        if($method==='pix'&&$card!==[])throw new Problem('Pix não aceita dados de cartão.');
        $paymentMethod=$method==='pix'?'pix':($card['payment_method_id']??'');
        if(!is_string($paymentMethod)||!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$paymentMethod))throw new Problem('Meio de pagamento tokenizado inválido.');
        if($method!=='pix'){
            if(!is_string($card['token']??null)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$card['token']))throw new Problem('Token do componente seguro ausente.');
            if((string)($card['installments']??'1')!=='1')throw new Problem('Este teste aceita somente uma parcela.');
            if(isset($card['issuer_id'])&&!preg_match('/^[0-9]{1,20}$/D',(string)$card['issuer_id']))throw new Problem('Emissor inválido.');
            $payer=$card['payer']??[];if(!is_array($payer)||array_diff(array_keys($payer),['email','identification']))throw new Problem('Dados de pagador não permitidos.');
            if(isset($payer['email'])&&(!is_string($payer['email'])||!filter_var($payer['email'],FILTER_VALIDATE_EMAIL)||strlen($payer['email'])>254))throw new Problem('E-mail de pagador de teste inválido.');
            $ident=$payer['identification']??null;
            if($ident!==null&&(!is_array($ident)||array_diff(array_keys($ident),['type','number'])||($ident['type']??'')!=='CPF'||($ident['number']??'')!=='12345678909'))throw new Problem('Use somente o CPF de teste publicado pelo Mercado Pago.');
        }
        return ['amount'=>$amount,'destination'=>$destination,'method'=>$method,'payment_method_id'=>$paymentMethod,'card'=>$card];
    }
    public function create(int $actor,array $data,string $key): array {
        $this->d->admin($actor);$this->enabled();self::key($key);$p=$this->input($data);
        if($this->d->db->row('SELECT actor_id FROM payment_test_abandoned WHERE actor_id=? AND idempotency_key=?',[$actor,$key]))throw new Problem('O envio desta tentativa foi cancelado. Prepare um novo teste.',409,'test_submission_abandoned');
        // Só o hash do token/dados efêmeros participa da idempotência; nada sensível é persistido.
        $hash=hash_hmac('sha256',Domain::canonical($p),CredentialKey::get($this->d->config));
        $prior=$this->d->db->row('SELECT * FROM payment_test_intents WHERE actor_id=? AND idempotency_key=?',[$actor,$key]);
        if($prior){if(!hash_equals($prior['payload_hash'],$hash))throw new Problem('A chave já foi utilizada com outros dados. Consulte o pagamento original.',409,'idempotency_conflict');return $this->read($actor,$prior['id']);}
        $c=$this->provider->credentials();$methods=$this->provider->methods();$type=$p['method']==='pix'?'bank_transfer':$p['method'];
        if(($methods[$p['payment_method_id']]??'')!==$type)throw new Problem('Este meio não está disponível para a aplicação de teste. Nenhum pagamento foi enviado.',409,'test_method_unavailable');
        if($p['method']!=='pix')$this->provider->card($p['card']['token'],$p['method']);
        $id=bin2hex(random_bytes(16));$reference='piscina-test-'.$id;
        $inserted=$this->d->db->transaction(function()use($actor,$p,$hash,$key,$id,$reference,$c){
            // Apenas metadados locais. Nunca mantenha o gate econômico durante chamadas HTTP.
            $this->d->db->row('SELECT id FROM users WHERE id=? FOR UPDATE',[$actor]);$this->d->admin($actor);
            if($this->d->db->row('SELECT actor_id FROM payment_test_abandoned WHERE actor_id=? AND idempotency_key=?',[$actor,$key]))throw new Problem('Envio cancelado antes do registro.',409,'test_submission_abandoned');
            $prior=$this->d->db->row('SELECT * FROM payment_test_intents WHERE actor_id=? AND idempotency_key=?',[$actor,$key]);
            if($prior){if(!hash_equals($prior['payload_hash'],$hash))throw new Problem('A chave já possui outras condições.',409,'idempotency_conflict');return $prior['id'];}
            $this->d->db->run('INSERT INTO payment_test_intents(id,actor_id,idempotency_key,payload_hash,external_reference,amount,destination,method,payment_method_id,collector_id,credential_fingerprint,created_at,authorized_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$id,$actor,$key,$hash,$reference,$p['amount'],$p['destination'],$p['method'],$p['payment_method_id'],(string)$c['user_id'],$this->provider->fingerprint()]);
            $this->event($id,'AUTHORIZED',['admin_id'=>(string)$actor,'amount'=>$p['amount'],'currency'=>'BRL','credit_asset'=>'MR','destination'=>$p['destination'],'method'=>$p['method'],'experimental'=>true]);return $id;
        });
        if($inserted!==$id)return $this->read($actor,$inserted);
        $body=['transaction_amount'=>'__EXACT_AMOUNT__','description'=>'Pagamento de TESTE da Piscina - sem dinheiro real','external_reference'=>$reference,'payment_method_id'=>$p['payment_method_id'],'metadata'=>['piscina_payment_test'=>$id,'scope'=>'admin_test'],'payer'=>['email'=>$this->d->config['payments_test']['payer_email']??'pagador.teste@example.com']];
        if($p['method']!=='pix'){$body+=array_intersect_key($p['card'],array_flip(['token','issuer_id']));$body['installments']=1;$body['payer']=array_replace($body['payer'],$p['card']['payer']??[]);}
        if(!empty($this->d->config['payments_test']['webhook_secret']))$body['notification_url']=rtrim($this->d->config['url'],'/').($this->d->config['base_path']??'').'/payment-test-webhook.php';
        try{
            $response=$this->provider->call('POST','/v1/payments',$body,'piscina-test-'.$id,$p['amount']);
            $providerId=(string)($response['id']??'');if(!preg_match('/^[0-9]{1,30}$/D',$providerId))throw new Problem('O provedor não retornou um identificador válido. Consulte o resultado.',503,'test_provider_invalid');
            // Criação não autoriza crédito. Sempre faça uma consulta independente autenticada.
            $this->bind($id,$providerId);return $this->synchronize($id,$this->provider->payment($providerId));
        }catch(Problem $e){
            if($e->error==='test_provider_rejected')$this->d->db->run("UPDATE payment_test_intents SET state=IF(provider_id IS NULL AND state='SUBMITTING','FAILED',IF(state='SUBMITTING','UNKNOWN',state)),error_code=? WHERE id=?",[$e->error,$id]);
            else $this->markUnknown($id,$e->error);
            return $this->read($actor,$id);
        }
    }
    private function bind(string $id,string $providerId): void {
        $this->d->db->transaction(function()use($id,$providerId){$r=$this->row($id,true);if($r['provider_id']!==null&&$r['provider_id']!==$providerId)throw new Problem('Identificador divergente do provedor.',409,'test_response_mismatch');$this->d->db->run('UPDATE payment_test_intents SET provider_id=? WHERE id=?',[$providerId,$id]);});
    }
    private function markUnknown(string $id,string $code): void {
        $this->d->db->run("UPDATE payment_test_intents SET state=IF(credit_transaction_id IS NULL AND state IN ('SUBMITTING','UNKNOWN'),'UNKNOWN',state),error_code=? WHERE id=?",[preg_match('/^[a-z_]{1,40}$/D',$code)?$code:'test_provider_unavailable',$id]);
    }
    private function row(string $id,bool $lock=false): array {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('Identificador inválido.');return $this->d->db->row('SELECT * FROM payment_test_intents WHERE id=?'.($lock?' FOR UPDATE':''),[$id])??throw new Problem('Pagamento de teste não encontrado.',404);}
    public function refresh(int $actor,string $id): array {
        $this->read($actor,$id);$this->enabled();$r=$this->row($id);
        if($r['credential_fingerprint']!==$this->provider->fingerprint())throw new Problem('As credenciais mudaram. Restaure a configuração original para consultar este teste.',409,'test_credentials_changed');
        try{
            if($r['provider_id']!==null)$p=$this->provider->payment($r['provider_id']);
            else{$search=$this->provider->search($r['external_reference']);$rows=$search['results']??[];if(!is_array($rows)||count($rows)!==1){if(count($rows)>1)throw new Problem('Mais de um resultado para a referência. O crédito foi bloqueado.',409,'test_response_mismatch');$this->markUnknown($id,'test_not_found_yet');return $this->read($actor,$id);}$p=$this->provider->payment((string)$rows[0]['id']);}
            return $this->synchronize($id,$p);
        }catch(Problem $e){if($e->status<500)throw $e;$this->markUnknown($id,$e->error);return $this->read($actor,$id);}
    }
    public function validate(array $r,array $p): array {
        $amount=null;try{$amount=Exact::units($p['transaction_amount']??null,2);}catch(Problem){}
        if(($p['live_mode']??null)!==false||!preg_match('/^[0-9]{1,30}$/D',(string)($p['id']??''))||($r['provider_id']!==null&&$r['provider_id']!==(string)$p['id'])||($p['external_reference']??'')!==$r['external_reference']||($p['currency_id']??'')!=='BRL'||$amount!==$r['amount']||(string)($p['collector_id']??'')!==$r['collector_id']||($p['payment_method_id']??'')!==$r['payment_method_id']||($p['payment_type_id']??'')!==($r['method']==='pix'?'bank_transfer':$r['method'])||($p['metadata']['piscina_payment_test']??'')!==$r['id']||($p['metadata']['scope']??'')!=='admin_test')throw new Problem('Valor, moeda, referência, coletor, meio ou ambiente de teste não correspondem à operação. Crédito bloqueado.',409,'test_response_mismatch');
        $status=$p['status']??'';if(!in_array($status,['approved','pending','in_process','authorized','rejected','cancelled','refunded','charged_back'],true))throw new Problem('Estado do provedor ainda não reconhecido. Nenhum crédito foi autorizado.',409,'test_status_unknown');
        if($status==='approved'&&(($p['captured']??null)!==true||isset($p['transaction_amount_refunded'])&&bccomp((string)$p['transaction_amount_refunded'],'0',2)!==0))throw new Problem('A aprovação não confirma captura integral. Crédito bloqueado.',409,'test_not_captured');
        return ['intent_id'=>$r['id'],'provider_id'=>(string)$p['id'],'external_reference'=>$r['external_reference'],'amount'=>$amount,'currency'=>'BRL','asset'=>'MR','collector_id'=>$r['collector_id'],'payment_method_id'=>$r['payment_method_id'],'method'=>$r['method'],'destination'=>$r['destination'],'actor_id'=>$r['actor_id'],'live_mode'=>false,'experimental'=>true,'status'=>$status,'updated_at'=>is_string($p['date_last_updated']??null)?substr($p['date_last_updated'],0,40):null];
    }
    private function synchronize(string $id,array $p): array {
        $r=$this->row($id);try{$snapshot=$this->validate($r,$p);}catch(Problem $e){$this->d->db->transaction(function()use($id,$e){$r=$this->row($id,true);$this->d->db->run("UPDATE payment_test_intents SET state=IF(credit_transaction_id IS NULL,'BLOCKED','REVIEW'),error_code=? WHERE id=?",[$e->error,$id]);$this->event($id,'VALIDATION_BLOCKED',['code'=>$e->error,'experimental'=>true]);});throw $e;}
        $json=Domain::canonical($snapshot);$hash=hash_hmac('sha256',$json,CredentialKey::get($this->d->config));
        $this->d->db->transaction(function()use($id,$snapshot,$json,$hash){
            $r=$this->row($id,true);$status=$snapshot['status'];
            if($r['credit_transaction_id']!==null){if(in_array($status,['refunded','charged_back'],true)){$this->d->db->run("UPDATE payment_test_intents SET state='REVIEW',provider_status=?,checked_at=UTC_TIMESTAMP(6),error_code='test_reversal_review' WHERE id=?",[$status,$id]);$this->event($id,'REVERSAL_REVIEW',['status'=>$status,'experimental'=>true]);}return;}
            if($r['provider_updated_at']!==null&&$snapshot['updated_at']!==null&&strcmp($r['provider_updated_at'],$snapshot['updated_at'])>0)return;
            $state=match($status){'approved'=>'READY_TO_CREDIT','rejected'=>'REJECTED','cancelled'=>'CANCELLED','refunded','charged_back'=>'CANCELLED',default=>'PENDING'};
            $this->d->db->run('UPDATE payment_test_intents SET provider_id=?,provider_status=?,provider_updated_at=?,state=?,verified_snapshot=?,verified_hash=?,checked_at=UTC_TIMESTAMP(6),error_code=NULL WHERE id=?',[$snapshot['provider_id'],$status,$snapshot['updated_at'],$state,$json,$hash,$id]);
            $this->event($id,'PROVIDER_CHECK',['provider_id'=>$snapshot['provider_id'],'status'=>$status,'live_mode'=>false,'experimental'=>true]);
        });
        $r=$this->row($id);
        if($r['state']==='READY_TO_CREDIT'&&$r['credit_transaction_id']===null)$this->d->operation((int)$r['actor_id'],'admin.payment_test.credit',['intent_id'=>$id],'payment-test-credit-'.$id);
        $out=$this->read((int)$r['actor_id'],$id);
        if($r['method']==='pix'){
            $t=$p['point_of_interaction']['transaction_data']??[];$visual=[];
            if(is_string($t['qr_code_base64']??null)&&strlen($t['qr_code_base64'])<=200000){$bytes=base64_decode($t['qr_code_base64'],true);$image=$bytes===false?false:@getimagesizefromstring($bytes);if($image&&$image[2]===IMAGETYPE_PNG&&$image[0]<=2048&&$image[1]<=2048)$visual['image']='data:image/png;base64,'.$t['qr_code_base64'];}
            if(is_string($t['qr_code']??null)&&strlen($t['qr_code'])<=4096&&preg_match('/^[\x20-\x7e]+$/D',$t['qr_code']))$visual['code']=$t['qr_code'];
            if($visual)$out['pix_preview']=$visual;
        }
        return $out;
    }
    public function cancel(int $actor,string $id): array {
        $r=$this->read($actor,$id);$this->enabled();if($r['credit_transaction_id']!==null)throw new Problem('Teste já creditado. Não há estorno automático de saldo do jogo.',409);
        if(!$r['provider_id'])throw new Problem('Resultado ainda desconhecido. Consulte o Mercado Pago antes de cancelar.',409);
        $r=$this->refresh($actor,$id);if($r['credit_transaction_id']!==null)throw new Problem('O provedor confirmou o pagamento; o crédito foi registrado. Não houve cancelamento.',409);
        if(in_array($r['state'],['REJECTED','CANCELLED'],true))return $r;
        if($r['state']!=='PENDING')throw new Problem('Revise o estado antes de cancelar.',409);
        $this->provider->call('PUT','/v1/payments/'.$r['provider_id'],['status'=>'cancelled'],'piscina-test-cancel-'.$id);
        return $this->refresh($actor,$id);
    }
    private function event(string $id,string $kind,array $data): void {$this->d->db->run('INSERT INTO payment_test_events(intent_id,kind,data,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$id,$kind,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
    public function read(int $actor,string $id): array {
        $this->d->admin($actor);if(!self::installed($this->d->db))throw new Problem('Instale a migração 007.',409);$r=$this->row($id);if((string)$actor!==$r['actor_id'])throw new Problem('Pagamento de teste de outro administrador.',403);
        unset($r['payload_hash'],$r['credential_fingerprint'],$r['verified_hash'],$r['verified_snapshot']);$r['experimental']=true;
        $r['events']=$this->d->db->all('SELECT kind,data,created_at FROM payment_test_events WHERE intent_id=? ORDER BY id DESC LIMIT 24',[$id]);
        $r['pix_notice']=$r['method']==='pix'?'Pix no sandbox de Checkout Bricks pode permanecer pendente. Não escaneie códigos em aplicativo bancário. Não há aprovação local nem crédito enquanto o Mercado Pago não confirmar.':null;return $r;
    }
    public function list(int $actor,array $q=[]): array {
        $this->d->admin($actor);if(!self::installed($this->d->db))return ['rows'=>[],'installed'=>false,'sources'=>[],'has_more'=>false];$off=max(0,min(100000,(int)($q['offset']??0)));
        $rows=$this->d->db->all("SELECT id,amount,destination,method,state,provider_id,created_at,credited_at,credit_transaction_id FROM payment_test_intents WHERE actor_id=? ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET $off",[$actor]);
        $sources=[];foreach(['wallet','treasury','galeria_revenue'] as $dest){$code=$dest==='wallet'?'U:'.$actor.':MR:wallet':'T:MR:'.$dest;$sources[$dest]=['balance'=>$this->d->db->row('SELECT balance FROM accounts WHERE code=?',[$code])['balance']??'0','test_credits'=>$this->d->db->row('SELECT COALESCE(SUM(amount),0) total FROM payment_test_intents WHERE destination=? AND credit_transaction_id IS NOT NULL'.($dest==='wallet'?' AND actor_id=?':''),$dest==='wallet'?[$dest,$actor]:[$dest])['total']];}
        return ['rows'=>array_slice($rows,0,24),'installed'=>true,'has_more'=>count($rows)>24,'sources'=>$sources,'offset'=>$off,'experimental'=>true];
    }
    public function lookup(int $actor,string $key): array {
        $this->d->admin($actor);self::key($key);if(!self::installed($this->d->db))throw new Problem('Instale a migração 007.',409);
        $r=$this->d->db->row('SELECT id FROM payment_test_intents WHERE actor_id=? AND idempotency_key=?',[$actor,$key]);if(!$r)throw new Problem('Tentativa ainda não encontrada. Não presuma recusa ou aprovação.',404);return $this->read($actor,$r['id']);
    }
    public function abandon(int $actor,string $key): array {
        $this->d->admin($actor);$this->enabled();self::key($key);
        $id=$this->d->db->transaction(function()use($actor,$key){$this->d->db->row('SELECT id FROM users WHERE id=? FOR UPDATE',[$actor]);$this->d->admin($actor);$r=$this->d->db->row('SELECT id FROM payment_test_intents WHERE actor_id=? AND idempotency_key=?',[$actor,$key]);if($r)return $r['id'];$this->d->db->run('INSERT IGNORE INTO payment_test_abandoned(actor_id,idempotency_key,created_at) VALUES(?,?,UTC_TIMESTAMP(6))',[$actor,$key]);return null;});
        return $id?$this->read($actor,$id):['state'=>'CANCELLED','experimental'=>true,'message'=>'Tentativa não registrada cancelada; a chave não poderá enviar pagamentos.'];
    }
    public function webhook(string $query,string $signature,string $requestId,string $body): void {
        $this->enabled();$secret=$this->d->config['payments_test']['webhook_secret']??'';
        if(strlen($secret)<20)throw new Problem('Notificações de teste desativadas.',404);
        $ids=[];foreach(explode('&',$query) as $pair){$kv=explode('=',$pair,2);if(rawurldecode($kv[0])==='data.id')$ids[]=rawurldecode($kv[1]??'');}
        if(count($ids)!==1||!preg_match('/^[0-9]{1,30}$/D',$ids[0])||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$requestId))throw new Problem('Notificação inválida.',401);
        $parts=[];foreach(explode(',',$signature) as $pair){$kv=explode('=',trim($pair),2);if(count($kv)===2){if(isset($parts[$kv[0]]))throw new Problem('Assinatura inválida.',401);$parts[$kv[0]]=$kv[1];}}
        $ts=$parts['ts']??'';$v=$parts['v1']??'';$seconds=strlen($ts)===13?(int)substr($ts,0,10):(int)$ts;
        if(!preg_match('/^[0-9]{10}(?:[0-9]{3})?$/D',$ts)||abs(time()-$seconds)>300||!preg_match('/^[a-f0-9]{64}$/D',$v)||!hash_equals(hash_hmac('sha256','id:'.$ids[0].';request-id:'.$requestId.';ts:'.$ts.';',$secret),$v))throw new Problem('Assinatura de notificação inválida.',401);
        $n=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(($n['type']??'')!=='payment')return;
        // Corpo da notificação nunca é autoridade para status, valor ou ambiente.
        $p=$this->provider->payment($ids[0]);$reference=$p['external_reference']??'';$r=$this->d->db->row('SELECT id,credential_fingerprint FROM payment_test_intents WHERE external_reference=?',[$reference]);
        if(!$r)return;if($r['credential_fingerprint']!==$this->provider->fingerprint())throw new Problem('Credencial do teste mudou.',409);
        $this->synchronize($r['id'],$p);
    }
}
