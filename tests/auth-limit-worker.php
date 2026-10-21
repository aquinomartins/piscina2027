<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Worker exclusivo do banco piscina_test em demo.');
$input=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$_SERVER['REMOTE_ADDR']=$input['ip']??'203.0.113.25';
$auth=new Piscina\Auth(db(),array_replace(config(),$input['config']??[]));
file_put_contents($argv[1].'.ready','1');
$deadline=microtime(true)+20;
while(!is_file($input['barrier'])){if(microtime(true)>$deadline)throw new RuntimeException('Barreira expirada.');usleep(1000);}
try {
    $result=match($input['action']) {
        'limit'=>(function()use($auth,$input){$auth->limit($input['kind'],$input['identity'],$input['max'],$input['seconds']??900);return [];})(),
        'register'=>$auth->register($input['data']),
        default=>throw new RuntimeException('Ação de teste desconhecida.')
    };
    echo json_encode(['status'=>200,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(Piscina\Problem $e) {echo json_encode(['status'=>$e->status,'error'=>$e->error],JSON_THROW_ON_ERROR);}
catch(Throwable $e) {fwrite(STDERR,$e::class.': '.$e->getMessage());exit(1);}
