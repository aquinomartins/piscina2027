<?php
declare(strict_types=1);
// Teste CLI do transporte real: interrompe antes de abrir qualquer conexão.
namespace Piscina {
    final class PaymentTestConnectionIntercepted extends \RuntimeException {
        public function __construct(public string $url){parent::__construct('Conexão interrompida pelo teste local.');}
    }
    final class PaymentTestCurl {public array $options=[];}
    function curl_init(string $url): PaymentTestCurl {
        if(!isset($GLOBALS['payment_test_http_response']))throw new PaymentTestConnectionIntercepted($url);
        if(!str_starts_with($url,'https://api.mercadopago.com/'))throw new \RuntimeException('Host incorreto.');
        return new PaymentTestCurl();
    }
    function curl_setopt_array(PaymentTestCurl $curl,array $options): bool {$curl->options=$options;return true;}
    function curl_setopt(PaymentTestCurl $curl,int $key,mixed $value): bool {$curl->options[$key]=$value;return true;}
    function curl_exec(PaymentTestCurl $curl): bool {
        if($curl->options[CURLOPT_FOLLOWLOCATION]!==false||$curl->options[CURLOPT_SSL_VERIFYPEER]!==true||$curl->options[CURLOPT_SSL_VERIFYHOST]!==2||$curl->options[CURLOPT_PROTOCOLS]!==CURLPROTO_HTTPS)throw new \RuntimeException('Proteção TLS/redirect alterada.');
        foreach($GLOBALS['payment_test_http_response']['headers']??[] as $line)($curl->options[CURLOPT_HEADERFUNCTION])($curl,$line);
        ($curl->options[CURLOPT_WRITEFUNCTION])($curl,$GLOBALS['payment_test_http_response']['raw']);
        return $GLOBALS['payment_test_http_response']['ok']??true;
    }
    function curl_getinfo(PaymentTestCurl $curl,int $key): int {return $GLOBALS['payment_test_http_response']['status'];}
    function curl_close(PaymentTestCurl $curl): void {}
}
namespace {
    if(PHP_SAPI!=='cli')exit(1);
    require dirname(__DIR__).'/app/Problem.php';
    require dirname(__DIR__).'/app/PaymentTestTransport.php';
    require dirname(__DIR__).'/app/MercadoPagoTestHttp.php';
    $http=new Piscina\MercadoPagoTestHttp();$passed=0;
    $allowed=[
        ['GET','/users/me'],
        ['POST','/oauth/token'],['POST','/v1/payments'],
        ['GET','/v1/payments/12345'],['PUT','/v1/payments/12345'],
        ['GET','/v1/payments/search?external_reference=piscina-test-abcd&limit=2'],
        ['GET','/v1/card_tokens/0123456789abcdef'],['GET','/v1/payment_methods'],
    ];
    foreach($allowed as [$method,$path]){
        try{$http->request($method,$path);throw new RuntimeException('Rota válida bloqueada.');}
        catch(Piscina\PaymentTestConnectionIntercepted $e){if($e->url!=='https://api.mercadopago.com'.$path)throw new RuntimeException('Host ou esquema incorreto.');$passed++;}
    }
    $blocked=[
        ['GET','https://example.com/users/me'],['GET','//example.com/users/me'],
        ['POST','/users/me'],['DELETE','/v1/customers/test-customer'],
        ['POST','/v1/customers/test-customer'],['GET','/v1/customers/../payments'],
        ['GET','/v1/customers/search?email=real%40example.com'],
        ['GET','/users/me?access_token=NAO_PERMITIDO'],
        ['GET','/v1/customers/search?email=test_payer_1%40testuser.com&access_token=NAO_PERMITIDO'],
        ['POST','/v1/customers'],['GET','/v1/customers/23456-test-customer'],
        ['GET','/v1/customers/search?email=test_payer_1234567890%40testuser.com'],
    ];
    foreach($blocked as [$method,$path]){
        try{$http->request($method,$path);throw new RuntimeException('Rota insegura foi aceita.');}
        catch(Piscina\PaymentTestConnectionIntercepted){throw new RuntimeException('Rota insegura chegou à conexão.');}
        catch(LogicException){$passed++;}
    }
    echo "PASS Transporte HTTP: $passed verificações de rotas/host, zero conexões externas.\n";
    $diagnostics=0;$secret='TEST-SEGREDO_NAO_DEVE_APARECER';$email='privado@example.com';$card='PAN_CVV_CPF_NAO_DEVEM_APARECER';
    $safe=function(string $message)use($secret,$email,$card): void {foreach([$secret,$email,$card] as $private)if(str_contains($message,$private))throw new RuntimeException('Dado privado no diagnóstico.');};
    foreach([
        ['GET','/users/me','autenticação da conta'],
        ['POST','/oauth/token','credencial OAuth'],
        ['GET','/v1/payment_methods','consulta dos meios'],
        ['GET','/v1/card_tokens/0123456789abcdef','verificação do cartão'],
        ['GET','/v1/payments/search?external_reference=piscina-test-abcd&limit=2','busca do pagamento'],
        ['POST','/v1/payments','envio do pagamento'],
        ['GET','/v1/payments/12345','consulta do pagamento'],
        ['PUT','/v1/payments/12345','cancelamento do pagamento'],
    ] as [$method,$path,$stage]){
        $GLOBALS['payment_test_http_response']=['status'=>403,'raw'=>json_encode(['code'=>'PA_UNAUTHORIZED_RESULT_FROM_POLICIES','message'=>$secret.' '.$email.' '.$card,'access_token'=>$secret,'cause'=>[['description'=>$card]]])];
        try{$http->request($method,$path,null,['Authorization: Bearer '.$secret]);throw new RuntimeException('Recusa deveria falhar.');}
        catch(Piscina\Problem $e){$message=$e->getMessage();$safe($message);if($e->status!==422||$e->error!=='test_provider_rejected'||!str_contains($message,$stage)||!str_contains($message,'HTTP: 403')||!str_contains($message,'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'))throw new RuntimeException('Diagnóstico incompleto.');if(str_contains($message,'23456-test-customer')||str_contains($message,'test_payer_123')||str_contains($message,'0123456789abcdef'))throw new RuntimeException('Identificador privado na etapa.');$diagnostics++;}
    }
    foreach([
        [400,['error'=>'bad_request'],'bad_request'],[401,['error'=>'invalid_token'],'invalid_token'],
        [403,['error'=>'forbidden'],'forbidden'],[404,['error'=>'not_found'],'not_found'],
        [405,['cause'=>[['code'=>101]]],'101'],[406,['causes'=>[['code'=>128]]],'128'],
        [422,['error'=>$secret,'message'=>$card],'não informado'],
        [400,['error'=>'bad_request','cause'=>[['code'=>128,'description'=>$secret.' '.$email]]],'128'],
        [400,['error'=>'bad_request','cause'=>[['code'=>'unknown_private_code'],['code'=>116,'description'=>$card]]],'116'],
        [400,['error'=>'bad_request','causes'=>[['code'=>101]]],'101'],
        [400,['error'=>'bad_request','cause'=>[['code'=>$secret,'description'=>$email]]],'bad_request'],
        [400,['error'=>'bad_request','cause'=>['code'=>128]],'128'],
        [400,['error'=>'bad_request','errors'=>[['code'=>125]]],'125'],
        [400,['error'=>'bad_request','cause'=>[['code'=>3003,'description'=>$secret.' '.$email.' '.$card]]],'3003'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2067]]],'2067'],
        [400,['error'=>'bad_request','cause'=>[['code'=>4050]]],'4050'],
        [403,['code'=>'pa_unauthorized_result_from_policies'],'pa_unauthorized_result_from_policies'],
        [400,['error'=>'bad_request','cause'=>[['code'=>1]]],'1'],
        [400,['error'=>'bad_request','cause'=>[['code'=>3]]],'3'],
        [400,['error'=>'bad_request','cause'=>[['code'=>8]]],'8'],
        [400,['error'=>'bad_request','cause'=>[['code'=>23]]],'23'],
        [400,['error'=>'bad_request','cause'=>[['code'=>1000]]],'1000'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2002]]],'2002'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2004]]],'2004'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2006]]],'2006'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2007]]],'2007'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2009]]],'2009'],
        [400,['error'=>'bad_request','cause'=>[['code'=>2034]]],'2034'],
    ] as [$status,$body,$code]){
        $GLOBALS['payment_test_http_response']=['status'=>$status,'raw'=>json_encode($body)];
        try{$http->request('GET','/users/me');throw new RuntimeException('Recusa deveria falhar.');}catch(Piscina\Problem $e){$safe($e->getMessage());if(!$e instanceof Piscina\MercadoPagoTestFailure)throw new RuntimeException('Falha sem diagnóstico tipado.');$safe($e->diagnostic);if($e->error!=='test_provider_rejected'||$e->status!==422||!str_contains($e->getMessage(),'HTTP: '.$status)||!str_contains($e->getMessage(),'Código: '.$code))throw new RuntimeException('Classificação de recusa alterada.');$diagnostics++;}
    }
    foreach([0,302,429,500,503,504] as $status){
        $GLOBALS['payment_test_http_response']=['status'=>$status,'raw'=>$secret.' '.$card.' '.$email,'ok'=>$status!==0];
        try{$http->request('POST','/v1/payments');throw new RuntimeException('Falha deveria ser desconhecida.');}catch(Piscina\Problem $e){$safe($e->getMessage());if($e->status!==503||$e->error!=='test_provider_unavailable')throw new RuntimeException('Falha ambígua virou recusa definitiva.');$diagnostics++;}
    }
    $GLOBALS['payment_test_http_response']=['status'=>200,'raw'=>$secret.' '.$card];
    try{$http->request('GET','/users/me');throw new RuntimeException('Resposta inválida deveria falhar.');}catch(Piscina\Problem $e){$safe($e->getMessage());if($e->status!==503||$e->error!=='test_provider_invalid'||!str_contains($e->getMessage(),'autenticação da conta'))throw new RuntimeException('JSON inválido sem diagnóstico.');$diagnostics++;}
    $GLOBALS['payment_test_http_response']=['status'=>200,'raw'=>'{"id":12345,"live_mode":false}'];$ok=$http->request('GET','/users/me');if($ok['id']!=='12345'||$ok['live_mode']!==false)throw new RuntimeException('Resposta válida alterada.');$diagnostics++;
    foreach([
        ['message'=>'The email format is invalid. '.$secret.' '.$email,'expected'=>'formato de e-mail do cadastro recusado'],
        ['message'=>'The description is invalid. '.$card,'expected'=>'campo description recusado'],
        ['message'=>'The customer already exists. '.$secret,'expected'=>'cliente de teste já existente'],
    ] as $case){
        $GLOBALS['payment_test_http_response']=['status'=>400,'raw'=>json_encode(['error'=>'bad_request','message'=>$case['message']])];
        try{$http->request('POST','/v1/payments');throw new RuntimeException('Recusa deveria falhar.');}catch(Piscina\Problem $e){$safe($e->getMessage());if(!str_contains($e->getMessage(),$case['expected'])||!str_contains($e->getMessage(),'Código: bad_request'))throw new RuntimeException('Mensagem não classificada com frase fixa.');$diagnostics++;}
    }
    foreach([
        ['body'=>['error'=>'bad_request','details'=>['errors'=>[['code'=>8,'data'=>['field'=>'notification_url','message'=>'Invalid field '.$secret.' '.$email]]]]],'code'=>'8','fields'=>['notification_url']],
        ['body'=>['error'=>'bad_request','error_description'=>'transaction_amount must be numeric: '.$card],'code'=>'bad_request','fields'=>['transaction_amount']],
        ['body'=>['error'=>['code'=>1,'details'=>[['path'=>'payer.email','description'=>'Required property '.$email]]]],'code'=>'1','fields'=>['payer.email']],
        ['body'=>['error'=>'bad_request','cause'=>[['code'=>'bad_request','details'=>[['code'=>2034,'message'=>$secret]]]]],'code'=>'2034','fields'=>[]],
        ['body'=>['error'=>'bad_request','details'=>['description'=>'Invalid X-Idempotency-Key and installments '.$secret]],'code'=>'bad_request','fields'=>['X-Idempotency-Key','installments']],
    ] as $case){
        $GLOBALS['payment_test_http_response']=['status'=>400,'raw'=>json_encode($case['body'])];
        try{$http->request('POST','/v1/payments');throw new RuntimeException('Recusa deveria falhar.');}catch(Piscina\MercadoPagoTestFailure $e){$safe($e->diagnostic);if(!str_contains($e->diagnostic,'Código: '.$case['code'].'.'))throw new RuntimeException('Causa aninhada perdida.');foreach($case['fields'] as $field)if(!str_contains($e->diagnostic,$field))throw new RuntimeException('Nome de campo ausente.');$diagnostics++;}
    }
    $requestId='b2494284-839e-4b5c-b412-faf07c0843fe';
    foreach([
        ['headers'=>["HTTP/1.1 100 Continue\r\n","X-Request-Id: 00000000-0000-0000-0000-000000000000\r\n","HTTP/2 400\r\n","X-Request-Id: $requestId\r\n","Authorization: Bearer $secret\r\n"],'expected'=>$requestId],
        ['headers'=>["HTTP/2 400\r\n","X-Request-Id: $secret\r\n"],'expected'=>null],
        ['headers'=>["HTTP/2 400\r\n","X-Request-Id: $email\r\n"],'expected'=>null],
        ['headers'=>["HTTP/1.1 100 Continue\r\n","X-Request-Id: $requestId\r\n","HTTP/2 400\r\n"],'expected'=>null],
    ] as $case){
        $GLOBALS['payment_test_http_response']=['status'=>400,'raw'=>'{"error":"bad_request"}','headers'=>$case['headers']];
        try{$http->request('POST','/v1/payments');throw new RuntimeException('Recusa deveria falhar.');}catch(Piscina\MercadoPagoTestFailure $e){$safe($e->diagnostic);if($case['expected']!==null&&!str_contains($e->diagnostic,$case['expected'])||$case['expected']===null&&str_contains($e->diagnostic,'x-request-id'))throw new RuntimeException('Referência técnica não foi validada.');$diagnostics++;}
    }
    $privateValues=[$secret,$email,$card,'5480 8328 0103 3311','12345678909','a1234567890b1234567890c1234567890d','https://privado.example.com/recurso/12345678','aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
    foreach([
        ['message'=>'This application is not enabled for test payments.','expected'=>'this application is not enabled for test payments'],
        ['message'=>'Only production credentials are allowed.','expected'=>'only production credentials are allowed'],
        ['message'=>'Caller blocked by policy because request header is missing.','expected'=>'caller blocked by policy because request header is missing'],
        ['message'=>'Unsupported content-type or encoding utf-8.','expected'=>'unsupported content-type or encoding utf-8'],
        ['message'=>'The account is unavailable for this operation. '.implode(' ',$privateValues),'expected'=>'the account is unavailable for this operation'],
        ['message'=>implode(' ',$privateValues),'expected'=>null],
        ['message'=>'invalidPRIVATEVALUE applicationSECRET secretUNKNOWN','expected'=>null],
    ] as $case){
        $GLOBALS['payment_test_http_response']=['status'=>400,'raw'=>json_encode(['error'=>'bad_request','message'=>$case['message']])];
        try{$http->request('POST','/v1/payments');throw new RuntimeException('Recusa deveria falhar.');}catch(Piscina\MercadoPagoTestFailure $e){
            foreach($privateValues as $private)if(str_contains($e->diagnostic,$private))throw new RuntimeException('Valor privado em resumo técnico.');
            if($case['expected']!==null&&(!str_contains($e->diagnostic,'Termos técnicos da resposta')||!str_contains($e->diagnostic,$case['expected']))||$case['expected']===null&&str_contains($e->diagnostic,'Termos técnicos da resposta'))throw new RuntimeException('Resumo técnico não reconstruído corretamente.');$diagnostics++;
        }
    }
    unset($GLOBALS['payment_test_http_response']);
    echo "PASS Diagnóstico HTTP: $diagnostics verificações de etapas, status, classificação e privacidade; rede simulada, zero conexões externas.\n";
}
