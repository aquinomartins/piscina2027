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
            $context=self::context($method,$path,$status,$raw);
            if($ok!==false&&in_array($status,[400,401,403,404,405,406,422],true))throw new Problem('O Mercado Pago recusou a solicitação de teste. '.$context.' Compartilhe somente esta mensagem para identificar a API recusada; não envie credenciais.',422,'test_provider_rejected');
            throw new Problem('Não foi possível confirmar a resposta do Mercado Pago. '.$context.' Consulte o resultado antes de tentar outra operação.',503,'test_provider_unavailable');
        }
        try{return self::decode($raw);}catch(\Throwable){throw new Problem('Resposta do Mercado Pago inválida. '.self::context($method,$path,$status,'').' Nenhum crédito foi autorizado.',503,'test_provider_invalid');}
    }
    // Somente etapas fixas, status HTTP e códigos conhecidos. Nunca devolva o corpo/message do provedor.
    private static function context(string $method,string $path,int $status,string $raw): string {
        $stage=match(true){
            $path==='/users/me'=>'autenticação da conta (/users/me)',
            $path==='/oauth/token'=>'credencial OAuth (/oauth/token)',
            $path==='/v1/payment_methods'=>'consulta dos meios (/v1/payment_methods)',
            str_starts_with($path,'/v1/customers/search?')=>'busca do cadastro técnico (/v1/customers/search)',
            $path==='/v1/customers'=>'criação do cadastro técnico (/v1/customers)',
            str_starts_with($path,'/v1/customers/')=>'leitura do cadastro técnico (/v1/customers/{id})',
            str_starts_with($path,'/v1/card_tokens/')=>'verificação do cartão (/v1/card_tokens/{id})',
            str_starts_with($path,'/v1/payments/search?')=>'busca do pagamento (/v1/payments/search)',
            $path==='/v1/payments'=>'envio do pagamento (/v1/payments)',
            $method==='PUT'=>'cancelamento do pagamento (/v1/payments/{id})',
            default=>'consulta do pagamento (/v1/payments/{id})',
        };
        $code='não informado';
        try{
            $r=self::decode($raw);
            $known=['invalid_token','invalid_access_token','unauthorized','unauthorized_client','forbidden','invalid_client','invalid_scope','invalid_grant','invalid_request','unsupported_grant_type','not_found','bad_request','validation_error','invalid_parameters','local_rate_limited','too_many_requests','PA_UNAUTHORIZED_RESULT_FROM_POLICIES','PA_UNAUTHORIZED_RESULT_FROM_AUTHORIZATION'];
            $known=array_merge($known,array_map('strval',range(100,129)),['140','150','200','201','202','203','204','205','206','207','208','214']);
            foreach([$r['code']??null,$r['error']??null,$r['cause'][0]['code']??null,$r['causes'][0]['code']??null] as $candidate)if(is_string($candidate)&&in_array($candidate,$known,true)){$code=$candidate;break;}
        }catch(\Throwable){}
        return 'Etapa: '.$stage.'. HTTP: '.($status>0?(string)$status:'sem resposta').'. Código: '.$code.'.';
    }
    // Preserva tokens numéricos JSON como strings decimais, sem passar por ponto flutuante.
    public static function decode(string $raw): array {
        $exact=preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',static fn($m)=>isset($m[1])?json_encode($m[1],JSON_THROW_ON_ERROR):$m[0],$raw);
        $d=json_decode($exact,true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new \UnexpectedValueException();return $d;
    }
}
