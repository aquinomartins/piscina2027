<?php
declare(strict_types=1);
namespace Piscina;

final class MercadoPagoSandbox {
    private ?array $credential=null;
    public function __construct(private array $config,private PaymentTestTransport $http=new MercadoPagoTestHttp()){}
    public function fingerprint(): string { $c=$this->config['payments_test']??[];return hash('sha256',Domain::canonical(['client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'','collector_id'=>$c['collector_id']??'','mode'=>$c['mode']??''])); }
    public function credentials(): array {
        if($this->credential)return $this->credential;$c=$this->config['payments_test']??[];
        if(!is_array($c)||($c['enabled']??false)!==true||($c['mode']??'')!=='test'||!is_string($c['client_id']??null)||!preg_match('/^[0-9]{3,30}$/D',$c['client_id'])||!is_string($c['client_secret']??null)||strlen($c['client_secret'])<16||!is_string($c['collector_id']??null)||!preg_match('/^[0-9]{1,30}$/D',$c['collector_id']))throw new Problem('Pagamentos de teste desativados ou configuração privada incompleta. Configure OAuth de teste e o coletor esperado.',409,'test_not_configured');
        $file=rtrim($this->config['storage'],'/').'/mercadopago-test-credentials.json';$fingerprint=$this->fingerprint();
        if(is_file($file)){$cache=json_decode((string)@file_get_contents($file),true);if(is_array($cache)&&($cache['fingerprint']??'')===$fingerprint&&($cache['expires_at']??0)>time())return $this->credential=$this->validateCredential($cache['credential']??[]);}
        // O ambiente é atestado pela resposta OAuth recebida via TLS, antes de qualquer pagamento.
        $r=$this->http->request('POST','/oauth/token',json_encode(['grant_type'=>'client_credentials','client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'test_token'=>true],JSON_THROW_ON_ERROR));
        $safe=$this->validateCredential($r);$seconds=min(300,(int)($r['expires_in']??0)-30);if($seconds<=0)throw new Problem('O Mercado Pago não confirmou a validade da credencial de teste.',409,'test_environment_unverified');
        $tmp=tempnam(dirname($file),'.mp-test-');if($tmp===false)throw new Problem('Armazenamento privado indisponível para o teste.',503);
        try{if(!chmod($tmp,0600)||file_put_contents($tmp,json_encode(['fingerprint'=>$fingerprint,'expires_at'=>time()+$seconds,'credential'=>$safe],JSON_THROW_ON_ERROR))===false||!rename($tmp,$file))throw new Problem('Não foi possível proteger a credencial de teste.',503);}finally{if(is_file($tmp))unlink($tmp);}
        return $this->credential=$safe;
    }
    private function validateCredential(array $r): array {
        $c=$this->config['payments_test'];
        if(($r['live_mode']??null)!==false||($r['token_type']??'')!=='bearer'||!is_string($r['access_token']??null)||!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$r['access_token'])||!is_string($r['public_key']??null)||!preg_match('/^[A-Za-z0-9_-]{20,150}$/D',$r['public_key'])||(string)($r['user_id']??'')!==(string)$c['collector_id'])throw new Problem('O Mercado Pago não atestou credenciais de teste para o coletor configurado. Nenhum pagamento será enviado.',409,'test_environment_unverified');
        return array_intersect_key($r,array_flip(['access_token','public_key','user_id','live_mode','token_type']));
    }
    public function call(string $method,string $path,?array $body=null,?string $key=null,?string $amount=null): array {
        $c=$this->credentials();$headers=['Authorization: Bearer '.$c['access_token']];if($key!==null)$headers[]='X-Idempotency-Key: '.$key;
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
        if(($t['live_mode']??null)!==false||($t['public_key']??'')!==$c['public_key']||($t['id']??'')!==$id||($cards[($t['first_six_digits']??'').':'.($t['last_four_digits']??'')]??'')!==$method)throw new Problem('Somente cartões oficiais de teste, tokenizados no ambiente de teste, são aceitos. Nenhum pagamento foi enviado.',409,'test_card_required');
    }
    public function payment(string $id): array {if(!preg_match('/^[0-9]{1,30}$/D',$id))throw new Problem('Identificador de teste inválido.');return $this->call('GET','/v1/payments/'.$id);}
    public function search(string $reference): array {return $this->call('GET','/v1/payments/search?'.http_build_query(['external_reference'=>$reference,'limit'=>2]));}
}
