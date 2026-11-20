<?php
declare(strict_types=1);
namespace Piscina;

// Host fixo, TLS obrigatório, sem redirects, respostas limitadas e sem logs de payload/headers.
final class MercadoPagoTestHttp implements PaymentTestTransport {
    public function request(string $method,string $path,?string $body=null,array $headers=[]): array {
        if(!extension_loaded('curl'))throw new Problem('Pagamentos de teste exigem a extensão cURL. O restante do app não foi alterado.',409,'test_curl_missing');
        $proofRoute=$method==='GET'&&($path==='/users/me'||preg_match('~^/v1/customers/(?:[A-Za-z0-9_-]{1,100}|search\?email=test_payer_[0-9]{1,10}%40testuser\.com)$~D',$path))||$method==='POST'&&$path==='/v1/customers';
        if(!$proofRoute&&(!in_array($method,['GET','POST','PUT'],true)||!preg_match('~^/(?:oauth/token|v1/payment_methods|v1/card_tokens/[A-Za-z0-9_-]{16,100}|v1/payments(?:/[0-9]{1,30}|/search\?[A-Za-z0-9_=&%.-]+)?)$~D',$path)))throw new \LogicException('Rota do provedor não permitida.');
        $curl=curl_init('https://api.mercadopago.com'.$path);$raw='';
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/json'],$headers),CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw): int {if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
        if($ok===false||$status<200||$status>=300){
            if($ok!==false&&in_array($status,[400,401,403,404,405,406,422],true))throw new Problem('O Mercado Pago não aceitou a solicitação de teste. Confira as credenciais e os dados de teste no painel do provedor.',422,'test_provider_rejected');
            throw new Problem('Não foi possível confirmar a resposta do Mercado Pago. Consulte o resultado antes de tentar outra operação.',503,'test_provider_unavailable');
        }
        try{return self::decode($raw);}catch(\Throwable){throw new Problem('Resposta do Mercado Pago inválida. Nenhum crédito foi autorizado.',503,'test_provider_invalid');}
    }
    // Preserva tokens numéricos JSON como strings decimais, sem passar por ponto flutuante.
    public static function decode(string $raw): array {
        $exact=preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',static fn($m)=>isset($m[1])?json_encode($m[1],JSON_THROW_ON_ERROR):$m[0],$raw);
        $d=json_decode($exact,true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new \UnexpectedValueException();return $d;
    }
}
