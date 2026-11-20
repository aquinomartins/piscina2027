<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new Piscina\Problem('Método não permitido.',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new Piscina\Problem('Requisição excede o limite.',413);
    (new Piscina\PaymentTests(service()))->webhook((string)($_SERVER['QUERY_STRING']??''),(string)($_SERVER['HTTP_X_SIGNATURE']??''),(string)($_SERVER['HTTP_X_REQUEST_ID']??''),file_get_contents('php://input',false,null,0,16385));
    http_response_code(200);echo 'ok';
}catch(Throwable $e){http_response_code($e instanceof Piscina\Problem?$e->status:503);echo 'not accepted';}
