<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))exit(1);
$c=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);service()->config['payments_test']=['enabled'=>true,'mode'=>'test'];touch($argv[1].'.ready');$until=microtime(true)+10;while(!is_file($c['barrier'])){if(microtime(true)>$until)exit(1);usleep(10000);}
echo json_encode(service()->operation(1,'admin.payment_test.credit',['intent_id'=>$c['id']],$c['key']),JSON_THROW_ON_ERROR);
