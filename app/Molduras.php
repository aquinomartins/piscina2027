<?php
declare(strict_types=1);
namespace Piscina;

final class Molduras {
    public function __construct(private Galeria $g){}
    public function frame(int $id): array {return $this->g->db->row('SELECT * FROM galeria_frames WHERE id=? FOR UPDATE',[$id])??throw new Problem('Moldura não encontrada.',404);}
    public function transferFacts(array $f): array {
        $locations=$this->g->db->all('SELECT l.point_id,l.starts_at,l.ends_at,p.space_id,p.name point_name,p.authorized_from,p.authorized_until,p.opens,p.closes,p.weekdays,p.conditions,p.status point_status,s.name space_name,s.address,s.status space_status FROM galeria_allocations l JOIN galeria_points p ON p.id=l.point_id JOIN galeria_spaces s ON s.id=p.space_id WHERE l.frame_id=? AND l.ends_at>? ORDER BY l.id',[$f['id'],$this->g->now]);
        if(!$locations)throw new Problem('Moldura sem ponto autorizado para operar.',409);
        $blocks=$this->g->db->all('SELECT starts_at,ends_at FROM galeria_blocks WHERE ends_at>? AND (frame_id=? OR point_id IN (SELECT point_id FROM galeria_allocations WHERE frame_id=?)) ORDER BY id',[$this->g->now,$f['id'],$f['id']]);
        return ['frame_version'=>$f['version'],'locations'=>$locations,'blocks'=>$blocks];
    }
    public function busy(int $id): bool {return (bool)$this->g->db->row("SELECT id FROM galeria_agreements WHERE frame_id=? AND state IN ('RESERVED','EXHIBITING','SUSPENDED') LIMIT 1",[$id]);}
    private function spaceBusy(int $id): void {if($this->g->db->row("SELECT id FROM galeria_agreements WHERE space_id=? AND state IN ('RESERVED','EXHIBITING','SUSPENDED') LIMIT 1",[$id]))throw new Problem('Espaço com compromisso vigente. Preserve as condições aceitas.',409);}
    public function space(int $actor,array $d): array {
        $this->g->admin($actor,$d);$name=GaleriaInput::text($d['name']??null,120);$address=GaleriaInput::text($d['address']??null,255);$responsible=GaleriaInput::text($d['responsible']??null,120);
        $status=$d['status']??'ACTIVE';if(!in_array($status,['ACTIVE','SUSPENDED','ARCHIVED'],true))throw new Problem('Estado do espaço inválido.');
        if(!empty($d['space_id'])){$id=Domain::id($d['space_id']);$this->spaceBusy($id);if(!$this->g->db->row('SELECT id FROM galeria_spaces WHERE id=?',[$id]))throw new Problem('Espaço não encontrado.',404);$this->g->db->run('UPDATE galeria_spaces SET name=?,address=?,responsible=?,status=? WHERE id=?',[$name,$address,$responsible,$status,$id]);}
        else{$this->g->db->run('INSERT INTO galeria_spaces(name,address,responsible,status,created_at) VALUES(?,?,?,?,?)',[$name,$address,$responsible,$status,$this->g->now]);$id=(int)$this->g->db->pdo->lastInsertId();}
        (new GaleriaAvailability($this->g))->space($id);return ['space_id'=>(string)$id];
    }
    public function point(int $actor,array $d): array {
        $this->g->admin($actor,$d);$space=Domain::id($d['space_id']??null);if(!empty($d['point_id']))$this->spaceBusy($space);
        if(!$this->g->db->row("SELECT id FROM galeria_spaces WHERE id=? AND status='ACTIVE'",[$space]))throw new Problem('Espaço não operacional.',409);
        $from=GaleriaInput::date($d['authorized_from']??null);$until=GaleriaInput::date($d['authorized_until']??null);if($until<=$from)throw new Problem('Autorização deve ter fim posterior ao início.');
        $opens=$d['opens']??'00:00';$closes=$d['closes']??'24:00';
        if(!is_string($opens)||!is_string($closes)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$opens)||!preg_match('/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D',$closes)||$opens>=$closes)throw new Problem('Horário de funcionamento inválido.');
        $days=$d['weekdays']??[1,2,3,4,5,6,7];if(!is_array($days)||!$days||count($days)>7)throw new Problem('Informe os dias da semana.');$days=array_unique(array_map(fn($v)=>GaleriaInput::integer($v,1,7),$days));sort($days);
        $status=$d['status']??'ACTIVE';if(!in_array($status,['ACTIVE','SUSPENDED','ARCHIVED'],true))throw new Problem('Estado do ponto inválido.');
        $args=[$space,GaleriaInput::text($d['name']??null,120),$from,$until,$opens,$closes,implode(',',$days),GaleriaInput::text($d['conditions']??null,2000),$status];
        if(!empty($d['point_id'])){$id=Domain::id($d['point_id']);$old=$this->g->db->row('SELECT * FROM galeria_points WHERE id=? FOR UPDATE',[$id])??throw new Problem('Ponto não encontrado.',404);$this->spaceBusy((int)$old['space_id']);$this->g->db->run('UPDATE galeria_points SET space_id=?,name=?,authorized_from=?,authorized_until=?,opens=?,closes=?,weekdays=?,conditions=?,status=? WHERE id=?',[...$args,$id]);}
        else{$this->g->db->run('INSERT INTO galeria_points(space_id,name,authorized_from,authorized_until,opens,closes,weekdays,conditions,status) VALUES(?,?,?,?,?,?,?,?,?)',$args);$id=(int)$this->g->db->pdo->lastInsertId();}
        (new GaleriaAvailability($this->g))->point($id);return ['point_id'=>(string)$id];
    }
    private function metadata(array $d): array {
        $m=$d['metadata']??null;if(!is_array($m))throw new Problem('Informe os metadados do equipamento.');
        GaleriaInput::fields($m,['schema_version','description','serial','equipment','condition','width','height','unit','orientation','resolution','formats','installation','rights','delivery']);
        $out=['schema_version'=>1];
        foreach(['description'=>2000,'equipment'=>120,'condition'=>255,'installation'=>2000,'rights'=>2000,'delivery'=>2000] as $k=>$max)$out[$k]=GaleriaInput::text($m[$k]??null,$max);
        foreach(['serial','resolution','formats'] as $k)$out[$k]=isset($m[$k])&&$m[$k]!==''?GaleriaInput::text($m[$k],255):'';
        foreach(['width','height'] as $k){$n=Exact::units($m[$k]??null,2);if(bccomp($n,'1000000',0)>0)throw new Problem('Dimensões excedem o limite.');$out[$k]=$m[$k];}
        if(!in_array($m['unit']??'', ['cm','mm','m'],true)||!in_array($m['orientation']??'', ['portrait','landscape','square','adaptive'],true))throw new Problem('Unidade ou orientação inválida.');
        $out['unit']=$m['unit'];$out['orientation']=$m['orientation'];return $out;
    }
    public function save(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$m=$this->metadata($d);$name=GaleriaInput::text($d['name']??null,120);$type=GaleriaInput::text($d['type_key']??null,40);if(!preg_match('/^[a-z0-9_-]+$/D',$type))throw new Problem('Tipo deve usar letras minúsculas, números ou hífen.');
        if(!empty($d['frame_id'])){$f=$this->frame(Domain::id($d['frame_id']));if($this->busy((int)$f['id']))throw new Problem('Não altere equipamento ou direitos com compromissos vigentes.',409);if((string)($d['version']??'')!==$f['version'])throw new Problem('Moldura alterada. Consulte novamente.',409);$id=(int)$f['id'];$v=(int)$f['version']+1;$this->g->db->run('UPDATE galeria_frames SET name=?,type_key=?,metadata=?,version=?,verified_at=NULL,verified_by=NULL WHERE id=?',[$name,$type,GaleriaInput::json($m),$v,$id]);$this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE'",[$id]);}
        else{$code=strtoupper(GaleriaInput::text($d['code']??null,40));if(!preg_match('/^[A-Z0-9_-]+$/D',$code))throw new Problem('Código público inválido.');if($this->g->db->row('SELECT id FROM galeria_frames WHERE code=?',[$code]))throw new Problem('Código público já cadastrado.',409);$this->g->db->run('INSERT INTO galeria_frames(code,authority_id,name,type_key,metadata,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$code,bin2hex(random_bytes(16)),$name,$type,GaleriaInput::json($m),$actor,$this->g->now]);$id=(int)$this->g->db->pdo->lastInsertId();$v=1;}
        $this->g->db->run('INSERT INTO galeria_frame_versions(frame_id,version,metadata,author_id,reason,created_at) VALUES(?,?,?,?,?,?)',[$id,$v,GaleriaInput::json(['name'=>$name,'type_key'=>$type,'metadata'=>$m]),$actor,$reason,$this->g->now]);
        (new GaleriaAvailability($this->g))->frame($id);return ['frame_id'=>(string)$id,'version'=>(string)$v];
    }
    public function allocate(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));$point=Domain::id($d['point_id']??null);$from=GaleriaInput::date($d['starts_at']??null);$to=GaleriaInput::date($d['ends_at']??null);
        if($to<=$from||$from<$this->g->now)throw new Problem('Alocação deve ser futura e ter duração positiva.');
        $this->pointAvailable($point,$from,$to);
        if($this->g->db->row("SELECT id FROM galeria_agreements WHERE frame_id=? AND state IN ('RESERVED','EXHIBITING','SUSPENDED') AND starts_at<? AND ends_at>?",[$f['id'],$to,$from]))throw new Problem('Mudança de local incompatível com contrato vigente.',409);
        foreach($this->g->db->all('SELECT * FROM galeria_allocations WHERE frame_id=? AND starts_at<? AND ends_at>? FOR UPDATE',[$f['id'],$to,$from]) as $old){if($old['starts_at']>=$from)throw new Problem('Já existe alocação nesse intervalo.',409);$this->g->db->run('UPDATE galeria_allocations SET ends_at=? WHERE id=?',[$from,$old['id']]);}
        if($this->g->db->row('SELECT id FROM galeria_allocations WHERE point_id=? AND starts_at<? AND ends_at>? LIMIT 1',[$point,$to,$from]))throw new Problem('Outro equipamento já ocupa esse ponto.',409);
        $this->g->db->run('INSERT INTO galeria_allocations(frame_id,point_id,starts_at,ends_at,operation_id) VALUES(?,?,?,?,?)',[$f['id'],$point,$from,$to,$this->g->op]);$allocationId=$this->g->db->pdo->lastInsertId();(new GaleriaAvailability($this->g))->frame((int)$f['id']);return ['allocation_id'=>$allocationId];
    }
    public function pointAvailable(int $id,string $from,string $to): array {
        $p=$this->g->db->row('SELECT p.*,s.status space_status FROM galeria_points p JOIN galeria_spaces s ON s.id=p.space_id WHERE p.id=? FOR UPDATE',[$id])??throw new Problem('Ponto não encontrado.',404);
        if($p['status']!=='ACTIVE'||$p['space_status']!=='ACTIVE'||$from<$p['authorized_from']||$to>$p['authorized_until'])throw new Problem('Ponto indisponível ou fora do prazo autorizado.',409);
        return $p;
    }
    public function operational(int $id,string $from,string $to,bool $hours=true): array {
        $f=$this->frame($id);if($f['status']!=='ACTIVE'||!$f['verified_at'])throw new Problem('Moldura sem verificação operacional vigente.',409);
        $a=$this->g->db->row('SELECT * FROM galeria_allocations WHERE frame_id=? AND starts_at<=? AND ends_at>=? ORDER BY starts_at DESC LIMIT 1',[$id,$from,$to])??throw new Problem('Moldura sem autorização de ponto para todo o intervalo.',409);
        $p=$this->pointAvailable((int)$a['point_id'],$from,$to);
        if($this->g->db->row('SELECT id FROM galeria_blocks WHERE (frame_id=? OR point_id=?) AND starts_at<? AND ends_at>? LIMIT 1',[$id,$p['id'],$to,$from]))throw new Problem('Período de manutenção/indisponibilidade.',409);
        if($hours){
            $tz=new \DateTimeZone('America/Sao_Paulo');$s=(new \DateTimeImmutable($from,new \DateTimeZone('UTC')))->setTimezone($tz);$end=(new \DateTimeImmutable($to,new \DateTimeZone('UTC')))->setTimezone($tz);
            for($day=$s->setTime(0,0),$i=0;$day<$end;$day=$day->modify('+1 day'),$i++){
                if($i>31)throw new Problem('Uma exposição pode durar no máximo 31 dias.');
                $open=new \DateTimeImmutable($day->format('Y-m-d').' '.$p['opens'],$tz);$close=new \DateTimeImmutable($day->format('Y-m-d').' '.$p['closes'],$tz);
                $segmentStart=$s>$day?$s:$day;$next=$day->modify('+1 day');$segmentEnd=$end<$next?$end:$next;
                if(!in_array($day->format('N'),explode(',',$p['weekdays']),true)||$segmentStart<$open||$segmentEnd>$close)throw new Problem('Exposição fora do horário publicado do ponto.',409);
            }
        }
        return $f+['point_id'=>$p['id'],'space_id'=>$p['space_id'],'point_name'=>$p['name']];
    }
    public function verify(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));if($f['status']==='ARCHIVED')throw new Problem('Moldura arquivada.',409);
        if($this->g->db->row('SELECT frame_id FROM galeria_frame_custody WHERE frame_id=?',[$f['id']]))throw new Problem('Moldura no fundo de custódia para avaliação. Não há reemissão automática.',409);
        if(!$this->g->db->row('SELECT id FROM galeria_allocations WHERE frame_id=? AND ends_at>?',[$f['id'],$this->g->now]))throw new Problem('Cadastre uma autorização de ponto antes da verificação.',409);
        $this->g->db->run("UPDATE galeria_frames SET status='ACTIVE',verified_at=?,verified_by=? WHERE id=?",[$this->g->now,$actor,$f['id']]);(new GaleriaAvailability($this->g))->frame((int)$f['id']);return ['frame_id'=>$f['id'],'state'=>'ACTIVE'];
    }
    public function archive(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));if($this->busy((int)$f['id']))throw new Problem('Resolva os compromissos antes de arquivar.',409);
        $this->g->db->run("UPDATE galeria_frames SET status='ARCHIVED' WHERE id=?",[$f['id']]);$this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE'",[$f['id']]);(new GaleriaAvailability($this->g))->frame((int)$f['id']);return ['frame_id'=>$f['id'],'state'=>'ARCHIVED'];
    }
    public function deleteDraft(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));if($f['status']!=='DRAFT'||$f['holder_id']!==null)throw new Problem('Somente rascunho nunca emitido pode ser excluído.',409);
        foreach(['galeria_proposals','galeria_offers','galeria_ownership','galeria_photos','galeria_blocks','galeria_allocations'] as $table)if($this->g->db->row("SELECT id FROM $table WHERE frame_id=? LIMIT 1",[$f['id']]))throw new Problem('Rascunho com vínculos deve ser arquivado.',409);
        $this->g->db->run('DELETE FROM galeria_allocations WHERE frame_id=?',[$f['id']]);$this->g->db->run('DELETE FROM galeria_frame_versions WHERE frame_id=?',[$f['id']]);$this->g->db->run('DELETE FROM galeria_frames WHERE id=?',[$f['id']]);return ['deleted_frame'=>$f['id']];
    }
    public function block(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));$from=GaleriaInput::date($d['starts_at']??null);$to=GaleriaInput::date($d['ends_at']??null);if($to<=$from||$from<$this->g->now)throw new Problem('Agende a indisponibilidade no futuro.');
        if($this->g->db->row("SELECT id FROM galeria_agreements WHERE frame_id=? AND state IN ('RESERVED','EXHIBITING','SUSPENDED') AND starts_at<? AND ends_at>?",[$f['id'],$to,$from]))throw new Problem('Compromisso existente: suspenda e resolva a exposição antes de agendar manutenção.',409);
        $this->g->db->run('INSERT INTO galeria_blocks(frame_id,starts_at,ends_at,reason,operation_id) VALUES(?,?,?,?,?)',[$f['id'],$from,$to,$this->g->reason($d),$this->g->op]);$id=$this->g->db->pdo->lastInsertId();(new GaleriaAvailability($this->g))->frame((int)$f['id']);return ['block_id'=>$id];
    }
    public function photo(int $actor,array $d): array {
        $this->g->admin($actor,$d);$f=$this->frame(Domain::id($d['frame_id']??null));$path=$d['path']??'';if(!is_string($path)||!preg_match('/^[a-f0-9]{32}\.jpg$/D',$path)||!is_file($this->g->domain->config['storage'].'/galeria/'.$path))throw new Problem('Use o envio autorizado de fotografia.');
        if((int)$this->g->db->row('SELECT COUNT(*) n FROM galeria_photos WHERE frame_id=?',[$f['id']])['n']>=8)throw new Problem('Limite de oito fotografias por Moldura.');
        $this->g->db->run('INSERT INTO galeria_photos(frame_id,path,sha,operation_id,created_at) VALUES(?,?,?,?,?)',[$f['id'],$path,hash_file('sha256',$this->g->domain->config['storage'].'/galeria/'.$path),$this->g->op,$this->g->now]);return ['photo_id'=>$this->g->db->pdo->lastInsertId()];
    }
    public function policy(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$space=Domain::id($d['space_id']??null);$recipient=Domain::id($d['recipient_id']??null);$this->g->user($recipient);
        if(!$this->g->db->row('SELECT id FROM galeria_spaces WHERE id=?',[$space]))throw new Problem('Espaço não encontrado.',404);
        $x=Exact::units($d['x']??null,0,true);$type=GaleriaInput::text($d['type_key']??null,40);
        if(($d['rights_ack']??false)!==true)throw new Problem('Confirme o efeito sobre direitos das cotas.');
        $terms=['mode'=>'CANONICAL_ESCROW','refund_before_start'=>'FULL','refund_interruption'=>'FULL','capture'=>'AFTER_CONFIRMED_COMPLETION','share_rights'=>'ORIGINAL_USER_UNTIL_CAPTURE','destination_rights'=>'RECIPIENT_FROM_NEXT_SNAPSHOT','reason'=>$reason];
        $this->g->db->run('INSERT INTO galeria_policies(space_id,type_key,x,recipient_id,terms,author_id,created_at) VALUES(?,?,?,?,?,?,?)',[$space,$type,$x,$recipient,GaleriaInput::json($terms),$actor,$this->g->now]);return ['policy_id'=>$this->g->db->pdo->lastInsertId(),'x'=>$x,'total'=>bcmul($x,'2',0)];
    }
    public function offer(int $actor,array $d): array {
        GaleriaInput::confirmed($d);$f=$this->frame(Domain::id($d['frame_id']??null));if($f['holder_id']!==null)$this->g->newBusiness();
        if($f['holder_id']===null)$this->g->admin($actor,$d);elseif((int)$f['holder_id']!==$actor)throw new Problem('Somente o titular define a revenda.',403);else $this->g->user($actor);
        if($f['status']!=='ACTIVE'||!$f['verified_at'])throw new Problem('Verifique equipamento e direitos antes de publicar.',409);
        if(!$this->g->db->row('SELECT id FROM galeria_allocations WHERE frame_id=? AND ends_at>?',[$f['id'],$this->g->now]))throw new Problem('Ponto sem autorização vigente.',409);
        $asset=$d['asset']??'';$amount=GaleriaInput::money($d['price']??null,$asset,true);$expires=GaleriaInput::date($d['expires_at']??null);if($expires<=$this->g->now)throw new Problem('Oferta deve ter validade futura.');
        $terms=GaleriaInput::text($d['terms']??null,2000);$v=(int)$this->g->db->row('SELECT COALESCE(MAX(version),0) n FROM galeria_offers WHERE frame_id=?',[$f['id']])['n']+1;
        $this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE'",[$f['id']]);
        $this->g->db->run('INSERT INTO galeria_offers(frame_id,seller_id,asset,amount,version,terms,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$f['id'],$f['holder_id'],$asset,$amount,$v,$terms,$expires,$this->g->now]);
        return ['offer_id'=>$this->g->db->pdo->lastInsertId(),'version'=>(string)$v,'amount'=>$amount,'asset'=>$asset,'checkout_blocked'=>$this->busy((int)$f['id'])];
    }
    public function cancelOffer(int $actor,array $d): array {
        GaleriaInput::confirmed($d);$o=$this->g->db->row('SELECT * FROM galeria_offers WHERE id=? FOR UPDATE',[Domain::id($d['offer_id']??null)])??throw new Problem('Oferta não encontrada.',404);
        if($o['seller_id']===null)$this->g->admin($actor,$d);elseif((int)$o['seller_id']!==$actor)throw new Problem('Oferta alheia.',403);
        $this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE id=? AND state='ACTIVE'",[$o['id']]);return ['offer_id'=>$o['id'],'state'=>'CANCELLED'];
    }
    public function buy(int $actor,array $d): array {
        $this->g->newBusiness();$this->g->user($actor);GaleriaInput::confirmed($d);
        $o=$this->g->db->row('SELECT * FROM galeria_offers WHERE id=? FOR UPDATE',[Domain::id($d['offer_id']??null)])??throw new Problem('Oferta não encontrada.',404);$f=$this->frame((int)$o['frame_id']);
        if($o['state']!=='ACTIVE'||$o['expires_at']<=$this->g->now||(string)($d['version']??'')!==$o['version']||(string)($d['amount']??'')!==$o['amount']||($d['asset']??'')!==$o['asset']||($d['seller_id']??null)!==$o['seller_id'])throw new Problem('Oferta alterada, encerrada ou vencida. Consulte novamente.',409);
        if($f['holder_id']!==$o['seller_id']||$f['status']!=='ACTIVE'||!$f['verified_at']||$this->busy((int)$f['id']))throw new Problem('Revenda indisponível enquanto houver reservas/exposições ou condições pendentes.',409);
        if($o['buyer_id']!==null&&(int)$o['buyer_id']!==$actor)throw new Problem('Oferta dirigida a outro comprador.',403);
        if((int)$f['holder_id']===$actor)throw new Problem('Você já é o titular.');
        if($f['holder_id']!==null)$this->g->user((int)$f['holder_id']);
        $allocation=$this->g->db->row('SELECT * FROM galeria_allocations WHERE frame_id=? AND ends_at>? ORDER BY starts_at LIMIT 1',[$f['id'],$this->g->now])??throw new Problem('Autorização do ponto vencida.',409);
        $this->pointAvailable((int)$allocation['point_id'],max($this->g->now,$allocation['starts_at']),$allocation['ends_at']);
        $to=$o['seller_id']===null?$this->g->domain->ledger->account(null,$o['asset'],'galeria_revenue'):$this->g->finance->userAccount((int)$o['seller_id'],$o['asset']);
        $this->g->finance->move($this->g->finance->userAccount($actor,$o['asset']),$to,$o['amount'],'GALERIA_SALE','offer',(int)$o['id'],'payment');
        $this->g->db->run('UPDATE galeria_frames SET holder_id=? WHERE id=?',[$actor,$f['id']]);
        $this->g->db->run('UPDATE galeria_ownership SET ends_at=? WHERE frame_id=? AND ends_at IS NULL',[$this->g->now,$f['id']]);
        $this->g->db->run('INSERT INTO galeria_ownership(frame_id,user_id,starts_at,operation_id) VALUES(?,?,?,?)',[$f['id'],$actor,$this->g->now,$this->g->op]);
        $this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE' AND id<>?",[$f['id'],$o['id']]);
        $this->g->db->run("UPDATE galeria_offers SET state='SOLD' WHERE id=?",[$o['id']]);
        $this->g->db->run('INSERT INTO galeria_sales(frame_id,offer_id,buyer_id,seller_id,asset,amount,operation_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$f['id'],$o['id'],$actor,$o['seller_id'],$o['asset'],$o['amount'],$this->g->op,$this->g->now]);
        return ['frame_id'=>$f['id'],'authority_id'=>$f['authority_id'],'holder_id'=>(string)$actor,'sale_id'=>$this->g->db->pdo->lastInsertId()];
    }
}
