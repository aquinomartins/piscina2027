<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Demonstração exige exclusivamente environment=demo e piscina_test vazio.');
foreach(['PISCINA_DEMO_ADMIN_PASSWORD','PISCINA_DEMO_PASSWORD'] as $name)if(strlen(getenv($name)?:'')<12)throw new RuntimeException('Defina senhas locais de demonstração com pelo menos 12 caracteres.');
(new Piscina\Installer(db(),config()))->migrate();if(db()->row('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Banco de demonstração deve estar vazio. Este script não apaga registros.');
function op(int $a,string $action,array $d,?string $key=null): array {return service()->operation($a,$action,$d,$key??bin2hex(random_bytes(16)));}
function user(string $name): array {db()->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[mb_strtolower($name).'@local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),$name]);$id=(int)db()->pdo->lastInsertId();$r=op(1,'admin.approve',['user_id'=>(string)$id]);return [$id,(int)$r['nft_id']];}
function withdraw(int $u,int $n): array {$q=service()->quote($u,$n);return op($u,'nft.withdraw',['nft_id'=>(string)$n,'payment'=>'MR','parameter_version'=>$q['parameter_version'],'quote_expires'=>$q['quote_expires']]);}
(new Piscina\Installer(db(),config()))->admin('admin@demo.invalid','Administração da demonstração',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
op(1,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT']);require __DIR__.'/galeria-fixtures.php';
$ui=in_array('--ui',$argv,true);$f=gfixture(4,!$ui);
if($ui){
    $point=ga('point.save',['space_id'=>(string)$f['space'],'name'=>'Ponto para nova aquisição','authorized_from'=>gd('-1 day'),'authorized_until'=>gd('+40 days'),'conditions'=>'Infraestrutura do piloto conferida']);
    $frame=ga('frame.save',['code'=>'PILOTO-NOVA','name'=>'Moldura disponível para aquisição','type_key'=>'digital','metadata'=>gmetadata()]);ga('frame.allocate',['frame_id'=>$frame['frame_id'],'point_id'=>$point['point_id'],'starts_at'=>gd('+1 second'),'ends_at'=>gd('+30 days')]);ga('frame.verify',['frame_id'=>$frame['frame_id']]);$offer=ga('offer',['frame_id'=>$frame['frame_id'],'asset'=>'MR','price'=>'100','expires_at'=>gd('+10 days'),'terms'=>'Direito de operação no espaço, sem transferência do equipamento']);$f['primary']=$frame+$offer;
}else{
    gprepare($f);
    if(in_array('--live',$argv,true)){
        // Relógio/agenda ajustados somente na fixture descartável, nunca pela API.
        $start=(new DateTimeImmutable())->modify('-10 minutes')->format('Y-m-d H:i:s.u');$end=(new DateTimeImmutable())->modify('+10 minutes')->format('Y-m-d H:i:s.u');
        db()->run('UPDATE galeria_allocations SET starts_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY)');
        db()->run('UPDATE galeria_events SET voting_starts=?,voting_ends=? WHERE id=?',[$start,$end,$f['event']['event_id']]);
        foreach(db()->all('SELECT * FROM galeria_agreements') as $a){$t=Piscina\GaleriaInput::decode($a['terms']);$t['starts_at']=$start;$t['ends_at']=$end;db()->run('UPDATE galeria_agreements SET starts_at=?,ends_at=?,terms=? WHERE id=?',[$start,$end,Piscina\GaleriaInput::json($t),$a['id']]);}
        foreach(db()->all('SELECT * FROM galeria_candidates') as $c){$t=Piscina\GaleriaInput::decode($c['snapshot']);$t['starts_at']=$start;$t['ends_at']=$end;db()->run('UPDATE galeria_candidates SET snapshot=? WHERE id=?',[Piscina\GaleriaInput::json($t),$c['id']]);}
        [$elector]=user('VisitanteCredenciado');ga('event.credential',['event_id'=>$f['event']['event_id'],'user_id'=>(string)$elector,'credential_ref'=>'presenca-ui-piloto']);ga('event.open',['event_id'=>$f['event']['event_id']]);$f['elector']=$elector;
    }elseif(in_array('--win',$argv,true)||in_array('--tie',$argv,true)){
        gvoteFixture($f,in_array('--tie',$argv,true)?[0,1]:[0,0,1]);gfinish($f);$f['receipt']=ga('event.settle',['event_id'=>$f['event']['event_id']]);$f['ranking']=(new Piscina\GaleriaRanking(db()))->read([]);
    }
}
echo json_encode($f,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
