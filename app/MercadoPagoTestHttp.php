<?php
declare(strict_types=1);
namespace Piscina;

// Host fixo, TLS obrigatório, sem redirects, respostas limitadas e sem logs de payload/headers.
final class MercadoPagoTestHttp implements PaymentTestTransport {
    public function request(string $method,string $path,?string $body=null,array $headers=[]): array {
        if(!extension_loaded('curl'))throw new Problem('Pagamentos de teste exigem a extensão cURL. O restante do app não foi alterado.',409,'test_curl_missing');
        $proofRoute=$method==='GET'&&$path==='/users/me';
        if(!$proofRoute&&(!in_array($method,['GET','POST','PUT'],true)||!preg_match('~^/(?:oauth/token|v1/payment_methods|v1/card_tokens/[A-Za-z0-9_-]{16,100}|v1/payments(?:/[0-9]{1,30}|/search\?[A-Za-z0-9_=&%.-]+)?)$~D',$path)))throw new \LogicException('Rota do provedor não permitida.');
        $curl=curl_init('https://api.mercadopago.com'.$path);$raw='';$requestId=null;
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/json'],$headers),CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$requestId): int {
            if(str_starts_with($line,'HTTP/'))$requestId=null;
            elseif(str_starts_with(strtolower($line),'x-request-id:')){$requestId=null;$value=trim(substr($line,13));if(self::validRequestId($value))$requestId=strtolower($value);}
            return strlen($line);
        },CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw): int {if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
        if($ok===false||$status<200||$status>=300){
            throw self::failure($method,$path,$status,$raw,$ok!==false,$requestId);
        }
        try{return self::decode($raw);}catch(\Throwable){$context=self::context($method,$path,$status,'').self::requestReference($requestId);throw new MercadoPagoTestFailure('Resposta do Mercado Pago inválida. '.$context.' Nenhum crédito foi autorizado.',503,'test_provider_invalid',$context);}
    }
    public static function failure(string $method,string $path,int $status,string $raw,bool $received=true,?string $requestId=null): Problem {
        $context=self::context($method,$path,$status,$raw).self::requestReference($requestId);
        if($received&&in_array($status,[400,401,403,404,405,406,422],true))return new MercadoPagoTestFailure('O Mercado Pago recusou a solicitação de teste. '.$context.' Compartilhe somente esta mensagem para identificar a API recusada; não envie credenciais.',422,'test_provider_rejected',$context);
        return new MercadoPagoTestFailure('Não foi possível confirmar a resposta do Mercado Pago. '.$context.' Consulte o resultado antes de tentar outra operação.',503,'test_provider_unavailable',$context);
    }
    private static function validRequestId(string $value): bool {return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value);}
    private static function requestReference(?string $value): string {return $value!==null&&self::validRequestId($value)?' Referência técnica do provedor (x-request-id): '.strtolower($value).'.':'';}
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
        $code='não informado';$messages=[];$fields=[];
        try{
            $r=self::decode($raw);
            $known=['invalid_token','invalid_access_token','unauthorized','unauthorized_client','forbidden','invalid_client','invalid_scope','invalid_grant','invalid_request','unsupported_grant_type','not_found','bad_request','validation_error','invalid_parameters','invalid_email','invalid_customer_email','customer_already_exists','local_rate_limited','too_many_requests','PA_UNAUTHORIZED_RESULT_FROM_POLICIES','PA_UNAUTHORIZED_RESULT_FROM_AUTHORIZATION'];
            $known=array_merge($known,array_map('strval',range(100,129)),['140','150','200','201','202','203','204','205','206','207','208','214']);
            // Códigos da Payments API: somente valores publicados, sem copiar description/message.
            $known=array_merge($known,['4','2042','2059','2062','2067','2072','2077','2123','2131','3000','3001','3002','3003','3004','3005','3006','3007','3008','3009','3010','3011','3012','3013','3014','3015','3016','3017','3018','3019','3020','3021','3022','3023','3024','3025','3026','3027','3028','3029','3030','3031','3032','3033','3034','4000','4001','4002','4003','4004','4005','4006','4012','4013','4015','4016','4017','4018','4019','4020','4021','4022','4023','4024','4025','4026','4027','4028','4029','4033','4037','4038','4039','4050','4051','4292','6033','7523','pa_unauthorized_result_from_policies']);
            $known=array_merge($known,['1','3','8','23','1000','2002','2004','2006','2007','2009','2034']);
            // Visite somente estruturas de erro conhecidas. Nunca guarde os textos/valores percorridos.
            $causes=[];$nodes=0;$visit=function(mixed $value,int $depth=0)use(&$visit,&$causes,&$messages,&$nodes): void {
                if(++$nodes>128||$depth>6)return;
                if(is_string($value)){if(strlen($value)<=2048&&count($messages)<32)$messages[]=$value;return;}
                if(!is_array($value))return;
                foreach(array_slice($value,0,32,true) as $key=>$item){
                    if(in_array($key,['code','error'],true)&&is_string($item))$causes[]=$item;
                    if(is_int($key)||in_array($key,['error','message','error_description','description','detail','details','cause','causes','errors','data','field','property','parameter','path','name'],true))$visit($item,$depth+1);
                }
            };$visit($r);
            $codes=array_values(array_unique(array_filter($causes,static fn($candidate)=>in_array($candidate,$known,true))));
            $specific=array_values(array_diff($codes,['bad_request','validation_error','invalid_request','invalid_parameters']));$code=$specific[0]??$codes[0]??'não informado';
            foreach(['transaction_amount','payment_method_id','payment_type_id','installments','issuer_id','token','card_token_id','cardtoken_id','cardholder_name','cardholder.name','cardholder.document.number','cardholder.document.type','payer.email','payer.identification.number','payer.identification.type','payer.type','payer','identification','notification_url','external_reference','metadata','capture','currency_id','public_key','access_token','client_id','collector_id','site_id','sponsor_id','application_fee','date_of_expiration','security_code','card_number','X-Idempotency-Key'] as $field){
                foreach($messages as $message)if(preg_match('/(?<![A-Za-z0-9_.])'.preg_quote($field,'/').'(?![A-Za-z0-9_.])/i',$message)){$fields[]=$field;break;}
            }
        }catch(\Throwable){}
        $hint=str_starts_with($path,'/v1/customers')?match($code){
            '101','customer_already_exists'=>' O provedor informou que o cliente já existe.',
            '106','invalid_email','invalid_customer_email'=>' O provedor recusou o formato do e-mail do cliente.',
            '107'=>' O provedor recusou o campo first_name.',
            '108'=>' O provedor recusou o campo last_name.',
            '116'=>' O provedor recusou o campo description.',
            '117'=>' O provedor recusou o campo metadata.',
            '118'=>' O provedor exige um objeto JSON no corpo.',
            '125'=>' O provedor informou parâmetros inválidos.',
            '128'=>' O provedor exige e-mail de sandbox no formato test_payer_N@testuser.com.',
            '150'=>' O provedor informou usuários incompatíveis.',
            default=>'',
        }:'';
        if($hint===''&&str_starts_with($path,'/v1/payments'))$hint=match($code){
            '1'=>' O provedor recusou os parâmetros enviados.',
            '3'=>' O provedor exige token de teste para esta operação.',
            '8'=>' O provedor recusou nomes de parâmetros.',
            '23'=>' O provedor recusou o formato da data.',
            '2006','2062','3003','3006','3008'=>' O provedor recusou o token do cartão.',
            '2009','3001'=>' O provedor exige o emissor do cartão.',
            '2034'=>' O provedor informou usuários incompatíveis.',
            '2067'=>' O provedor recusou o documento de identificação.',
            '2072','4002','4003','4023','4024','4037'=>' O provedor recusou o valor ou o tipo de transaction_amount.',
            '4050','4051'=>' O provedor recusou payer.email.',
            '4292'=>' O provedor exige o cabeçalho X-Idempotency-Key.',
            default=>'',
        };
        if($hint==='')foreach($messages as $message){
            if(!is_string($message)||strlen($message)>512)continue;
            $message=strtolower($message);
            // Classifique frases conhecidas, mas devolva somente textos fixos; nunca a mensagem recebida.
            $fixed=[
                'the email format is invalid'=>'formato de e-mail do cadastro recusado',
                'the customer already exist'=>'cliente de teste já existente',
                'the credentials are required'=>'credencial exigida pelo provedor',
                'the description is invalid'=>'campo description recusado',
                'the first_name is invalid'=>'campo first_name recusado',
                'the last_name is invalid'=>'campo last_name recusado',
                'the body must be a json object'=>'corpo JSON recusado',
                'invalid users involved'=>'usuários incompatíveis',
                'the name of the following parameters is wrong'=>'nomes de parâmetros recusados',
                'invalid card token'=>'token do cartão recusado',
                'invalid card_token_id'=>'token do cartão recusado',
                'token must be for test'=>'token de teste exigido pelo provedor',
                'invalid transaction_amount'=>'valor de transaction_amount recusado',
                'invalid value for transaction_amount'=>'valor de transaction_amount recusado',
                'must be numeric'=>'tipo numérico exigido pelo provedor',
                'expected number'=>'tipo numérico exigido pelo provedor',
                'expected string'=>'tipo de texto exigido pelo provedor',
                'cannot be null'=>'campo obrigatório ausente',
                "can't be null"=>'campo obrigatório ausente',
                'is required'=>'campo obrigatório ausente',
                'required property'=>'campo obrigatório ausente',
                'invalid idempotency'=>'chave de idempotência recusada',
                'json syntax'=>'formato JSON recusado',
                'json_syntax'=>'formato JSON recusado',
                'deserialize'=>'tipo ou estrutura do JSON recusados',
            ];
            foreach($fixed as $phrase=>$detail)if(str_contains($message,$phrase)){$hint=' Detalhe reconhecido: '.$detail.'.';break 2;}
        }
        return 'Etapa: '.$stage.'. HTTP: '.($status>0?(string)$status:'sem resposta').'. Código: '.$code.'.'.$hint.($fields?' Campos citados pelo provedor (sem valores): '.implode(', ',$fields).'.':'');
    }
    // Preserva tokens numéricos JSON como strings decimais, sem passar por ponto flutuante.
    public static function decode(string $raw): array {
        $exact=preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',static fn($m)=>isset($m[1])?json_encode($m[1],JSON_THROW_ON_ERROR):$m[0],$raw);
        $d=json_decode($exact,true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new \UnexpectedValueException();return $d;
    }
}

// Criada somente pelo transporte acima, que reduz a resposta a etapas/códigos fixos.
// Fica no mesmo arquivo para que a atualização do transporte seja um conjunto indivisível.
final class MercadoPagoTestFailure extends Problem {
    public function __construct(string $message,int $status,string $error,public readonly string $diagnostic){parent::__construct($message,$status,$error);}
}
