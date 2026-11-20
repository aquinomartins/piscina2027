<?php
declare(strict_types=1);
// Teste CLI do transporte real: interrompe antes de abrir qualquer conexão.
namespace Piscina {
    final class PaymentTestConnectionIntercepted extends \RuntimeException {
        public function __construct(public string $url){parent::__construct('Conexão interrompida pelo teste local.');}
    }
    function curl_init(string $url): never {throw new PaymentTestConnectionIntercepted($url);}
}
namespace {
    if(PHP_SAPI!=='cli')exit(1);
    require dirname(__DIR__).'/app/Problem.php';
    require dirname(__DIR__).'/app/PaymentTestTransport.php';
    require dirname(__DIR__).'/app/MercadoPagoTestHttp.php';
    $http=new Piscina\MercadoPagoTestHttp();$passed=0;
    $allowed=[
        ['GET','/users/me'],['POST','/v1/customers'],
        ['GET','/v1/customers/search?email=test_payer_1234567890%40testuser.com'],
        ['GET','/v1/customers/23456-test-customer'],
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
    ];
    foreach($blocked as [$method,$path]){
        try{$http->request($method,$path);throw new RuntimeException('Rota insegura foi aceita.');}
        catch(Piscina\PaymentTestConnectionIntercepted){throw new RuntimeException('Rota insegura chegou à conexão.');}
        catch(LogicException){$passed++;}
    }
    echo "PASS Transporte HTTP: $passed verificações de rotas/host, zero conexões externas.\n";
}
