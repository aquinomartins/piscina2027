<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents($argv[1].'.ready','1');while(!is_file($input['barrier']))usleep(5000);
try{if($input['action']==='test.deadlock'){ $tries=0;$r=db()->transaction(function()use($input,&$tries){$tries++;$first=(int)$input['data']['first'];$second=3-$first;db()->run('UPDATE retry_fixture SET value=value+1 WHERE id=?',[$first]);touch($input['data']['marker'].'.'.$first);$end=microtime(true)+5;while(!is_file($input['data']['marker'].'.'.$second)){if(microtime(true)>$end)throw new RuntimeException('Timeout na barreira de lock.');usleep(1000);}db()->run('UPDATE retry_fixture SET value=value+1 WHERE id=?',[$second]);return ['attempts'=>$tries];});}else{$r=service()->operation((int)$input['actor'],$input['action'],$input['data'],$input['key']);}echo json_encode(['ok'=>true,'data'=>$r]);}
catch(Throwable $e){echo json_encode(['ok'=>false,'message'=>$e->getMessage(),'status'=>$e instanceof Piscina\Problem?$e->status:500]);}
