<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
try{echo json_encode((new Piscina\Tasks(service(),config()))->run(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";}catch(Throwable $e){fwrite(STDERR,"Falha na tarefa: ".$e->getMessage()."\n");exit(1);}
