<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
try{$d=service();$u=$d->db->row("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");if(!$u)throw new Piscina\Problem('Crie o Admin antes do diagnóstico.',409);$r=(new Piscina\PaymentTests($d))->bootstrap((int)$u['id']);echo json_encode(array_intersect_key($r,array_flip(['enabled','reason','methods','environment_verified','verification_notice','experimental','currency','credit_asset','max_amount'])),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;exit($r['enabled']?0:1);}catch(Throwable $e){fwrite(STDERR,($e instanceof Piscina\Problem?$e->getMessage():'Diagnóstico indisponível. Confira a configuração privada.').PHP_EOL);exit(1);}
