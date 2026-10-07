<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$out=['php'=>PHP_VERSION,'64bit'=>PHP_INT_SIZE===8,'extensions'=>[]];foreach(['pdo_mysql','bcmath','gd','mbstring','openssl','fileinfo']as $ext)$out['extensions'][$ext]=extension_loaded($ext);
try{$c=config();$out['database']=db()->row('SELECT VERSION() version,@@version_comment engine,@@character_set_connection charset');$out['tables']=db()->all('SELECT table_name,engine FROM information_schema.tables WHERE table_schema=DATABASE()');$out['storage_writable']=is_writable($c['storage']);$out['environment']=$c['environment'];}catch(Throwable $e){$out['error']=$e->getMessage();}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
