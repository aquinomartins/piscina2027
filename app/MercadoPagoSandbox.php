<?php
declare(strict_types=1);
namespace Piscina;

final class MercadoPagoSandbox {
    private ?array $credential=null;
    public function __construct(private array $config,private PaymentTestTransport $http=new MercadoPagoTestHttp()){}
    private function source(): string {
        $c=$this->config['payments_test']??[];
        if(!is_array($c))throw new Problem('Confira o bloco payments_test em config/local.php.',409,'test_not_configured');
        $source=$c['credential_source']??(array_key_exists('public_key',$c)||array_key_exists('access_token',$c)?'test_credentials':'oauth');
        if(!is_string($source)||!in_array($source,['test_credentials','oauth'],true))throw new Problem('Use credential_source=test_credentials no bloco payments_test.',409,'test_not_configured');
        return $source;
    }
    public function fingerprint(): string {
        $c=$this->config['payments_test']??[];
        // Preserve a identidade das operações OAuth já registradas antes desta atualização.
        $fields=['client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'','collector_id'=>$c['collector_id']??'','mode'=>$c['mode']??''];
        if($this->source()!=='oauth')$fields=['credential_source'=>$this->source(),'client_id'=>$c['client_id']??'','collector_id'=>$c['collector_id']??'','mode'=>$c['mode']??'','public_key'=>$c['public_key']??'','access_token'=>$c['access_token']??''];
        return hash('sha256',Domain::canonical($fields));
    }
    public function credentials(): array {
        if($this->credential)return $this->credential;$c=$this->config['payments_test']??[];
        if(!is_array($c)||($c['enabled']??false)!==true||($c['mode']??'')!=='test'||!is_string($c['client_id']??null)||!preg_match('/^[0-9]{3,30}$/D',$c['client_id'])||!is_string($c['collector_id']??null)||!preg_match('/^[0-9]{1,30}$/D',$c['collector_id']))throw new Problem('Pagamentos de teste desativados ou configuração privada incompleta. Confira payments_test em config/local.php.',409,'test_not_configured');
        if($this->source()==='test_credentials')return $this->credential=$this->testCredentials($c);
        if($this->source()!=='oauth'||!is_string($c['client_secret']??null)||strlen($c['client_secret'])<16)throw new Problem('Configure Public Key e Access Token de teste em payments_test no config/local.php; use credential_source=test_credentials.',409,'test_not_configured');
        $file=rtrim($this->config['storage'],'/').'/mercadopago-test-credentials.json';$fingerprint=$this->fingerprint();
        if(is_file($file)){$cache=json_decode((string)@file_get_contents($file),true);if(is_array($cache)&&($cache['fingerprint']??'')===$fingerprint&&($cache['expires_at']??0)>time())return $this->credential=$this->validateCredential($cache['credential']??[]);}
        // O ambiente é atestado pela resposta OAuth recebida via TLS, antes de qualquer pagamento.
        $r=$this->http->request('POST','/oauth/token',json_encode(['grant_type'=>'client_credentials','client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'test_token'=>true],JSON_THROW_ON_ERROR));
        $safe=$this->validateCredential($r);$seconds=min(300,(int)($r['expires_in']??0)-30);if($seconds<=0)throw new Problem('O Mercado Pago não confirmou a validade da credencial de teste.',409,'test_environment_unverified');
        $tmp=tempnam(dirname($file),'.mp-test-');if($tmp===false)throw new Problem('Armazenamento privado indisponível para o teste.',503);
        try{if(!chmod($tmp,0600)||file_put_contents($tmp,json_encode(['fingerprint'=>$fingerprint,'expires_at'=>time()+$seconds,'credential'=>$safe],JSON_THROW_ON_ERROR))===false||!rename($tmp,$file))throw new Problem('Não foi possível proteger a credencial de teste.',503);}finally{if(is_file($tmp))unlink($tmp);}
        return $this->credential=$safe;
    }
    private function testCredentials(array $c): array {
        // Esta integração usa as credenciais TEST da Payments API. APP_USR não é uma alternativa.
        if(!is_string($c['public_key']??null)||!preg_match('/^TEST-[A-Za-z0-9_-]{16,145}$/D',$c['public_key'])||!is_string($c['access_token']??null)||!preg_match('/^TEST-[A-Za-z0-9_-]{16,295}$/D',$c['access_token']))throw new Problem('Copie a Public Key e o Access Token de Credenciais de teste da aplicação com API Pagamentos. Chaves de produção não são aceitas.',409,'test_not_configured');
        // O formato documentado também vincula o token à aplicação e à conta esperadas.
        if(!str_starts_with($c['access_token'],'TEST-'.$c['client_id'].'-')||!str_ends_with($c['access_token'],'-'.$c['collector_id']))throw new Problem('O Access Token de teste não corresponde ao número da aplicação ou ao User ID configurado. Confira os três dados da mesma aplicação.',409,'test_credentials_mismatch');
        $user=$this->http->request('GET','/users/me',null,['Authorization: Bearer '.$c['access_token']]);
        if(!is_string($user['id']??null)||$user['id']!==$c['collector_id']||($user['site_id']??'')!=='MLB')throw new Problem('O Mercado Pago não confirmou a conta recebedora brasileira configurada. Nenhum pagamento será enviado.',409,'test_credentials_mismatch');
        // Autenticar a conta permite preparar/tokenizar; não atesta live_mode por conta própria.
        return $this->directCredential($c,$this->readProof($c));
    }
    private function proofFile(): string {return rtrim($this->config['storage'],'/').'/mercadopago-test-environment.json';}
    private function readProof(array $c): bool {
        $file=$this->proofFile();$cache=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
        return is_array($cache)&&($cache['fingerprint']??'')===$this->fingerprint()&&is_int($cache['expires_at']??null)&&$cache['expires_at']>time()&&$cache['expires_at']<=time()+300&&($cache['user_id']??'')===$c['collector_id']&&($cache['live_mode']??null)===false&&in_array($cache['proof_source']??null,['card_token','payment'],true);
    }
    public function environmentVerified(): bool {return ($this->credentials()['live_mode']??null)===false;}
    public function requireEnvironment(): void {
        if(!$this->environmentVerified())throw new Problem('O ambiente de teste ainda não foi comprovado. Faça primeiro um teste com cartão oficial ou consulte um pagamento de teste existente. O Pix permanece bloqueado até a confirmação do servidor.',409,'test_environment_unverified');
    }
    private function rememberProof(string $source): void {
        if($this->source()!=='test_credentials')return;
        $c=$this->config['payments_test'];
        // Chamado somente após uma consulta autenticada confirmar live_mode=false. Sem token, PAN, CPF ou ID de recurso no cache.
        $this->writeProof($this->proofFile(),['fingerprint'=>$this->fingerprint(),'expires_at'=>time()+300,'user_id'=>$c['collector_id'],'proof_source'=>$source,'live_mode'=>false]);
        $this->credential=$this->directCredential($c,true);
    }
    private function directCredential(array $c,bool $verified): array {
        $out=['access_token'=>$c['access_token'],'public_key'=>$c['public_key'],'user_id'=>$c['collector_id'],'token_type'=>'bearer'];
        if($verified)$out['live_mode']=false;
        return $out;
    }
    private function writeProof(string $file,array $proof): void {
        $tmp=tempnam(dirname($file),'.mp-test-proof-');if($tmp===false)throw new Problem('Armazenamento privado indisponível para o teste.',503,'test_storage_unavailable');
        try{if(!chmod($tmp,0600)||file_put_contents($tmp,json_encode($proof,JSON_THROW_ON_ERROR))===false||!rename($tmp,$file))throw new Problem('Não foi possível proteger o atestado de teste.',503,'test_storage_unavailable');}finally{if(is_file($tmp))unlink($tmp);}
    }
    private function validateCredential(array $r): array {
        $c=$this->config['payments_test'];
        if(($r['live_mode']??null)!==false||($r['token_type']??'')!=='bearer'||!is_string($r['access_token']??null)||!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$r['access_token'])||!is_string($r['public_key']??null)||!preg_match('/^[A-Za-z0-9_-]{20,150}$/D',$r['public_key'])||(string)($r['user_id']??'')!==(string)$c['collector_id'])throw new Problem('O Mercado Pago não atestou credenciais de teste para o coletor configurado. Nenhum pagamento será enviado.',409,'test_environment_unverified');
        return array_intersect_key($r,array_flip(['access_token','public_key','user_id','live_mode','token_type']));
    }
    public function call(string $method,string $path,?array $body=null,?string $key=null,?string $amount=null): array {
        $c=$this->credentials();$headers=['Authorization: Bearer '.$c['access_token']];if($key!==null)$headers[]='X-Idempotency-Key: '.$key;
        if($method==='POST'&&$path==='/v1/payments'||$method==='PUT'&&preg_match('~^/v1/payments/[0-9]+$~D',$path))$this->requireEnvironment();
        $json=$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($amount!==null){$decimal=self::decimal($amount);$json=str_replace('"__EXACT_AMOUNT__"',$decimal,$json);}
        return $this->http->request($method,$path,$json,$headers);
    }
    public static function decimal(string $cents): string {Exact::bounded($cents);return bcdiv($cents,'100',2);}
    public function methods(): array {
        $rows=$this->call('GET','/v1/payment_methods');$out=[];foreach($rows as $r)if(is_array($r)&&($r['status']??'')==='active'&&in_array($r['payment_type_id']??'',['credit_card','debit_card','bank_transfer'],true)&&preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$r['id']??''))$out[$r['id']]=$r['payment_type_id'];return $out;
    }
    public function card(string $id,string $method): void {
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$id))throw new Problem('Token de cartão de teste inválido. Use o componente seguro do Mercado Pago.');
        $t=$this->call('GET','/v1/card_tokens/'.$id);$c=$this->credentials();
        $cards=['548083:3311'=>'credit_card','423564:5682'=>'credit_card','375365:6885'=>'credit_card','506776:8311'=>'debit_card'];
        $fresh=false;
        if(is_string($t['date_due']??null)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$t['date_due']))try{$fresh=(new \DateTimeImmutable($t['date_due']))->getTimestamp()>time();}catch(\Throwable){}
        // O SDK oficial documenta live_mode/status/date_due. public_key, quando retornada, também deve corresponder.
        if(($t['live_mode']??null)!==false||isset($t['public_key'])&&$t['public_key']!==$c['public_key']||($t['id']??'')!==$id||($t['status']??'')!=='active'||!$fresh||($cards[($t['first_six_digits']??'').':'.($t['last_four_digits']??'')]??'')!==$method)throw new Problem('Somente tokens ativos de cartões oficiais de teste, confirmados no servidor com live_mode=false, são aceitos. Nenhum pagamento foi enviado.',409,'test_card_required');
        $this->rememberProof('card_token');
    }
    public function payment(string $id): array {
        if(!preg_match('/^[0-9]{1,30}$/D',$id))throw new Problem('Identificador de teste inválido.');
        $r=$this->call('GET','/v1/payments/'.$id);$c=$this->credentials();
        if(($r['live_mode']??null)===false&&($r['id']??null)===$id&&($r['collector_id']??null)===$c['user_id'])$this->rememberProof('payment');
        return $r;
    }
    public function search(string $reference): array {return $this->call('GET','/v1/payments/search?'.http_build_query(['external_reference'=>$reference,'limit'=>2]));}
}
