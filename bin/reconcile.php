<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$result=db()->transaction(function(){db()->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return service()->ledger->reconcile();});
echo json_encode($result,JSON_PRETTY_PRINT)."\n";foreach($result as $rows)if($rows)exit(1);
