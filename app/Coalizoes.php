<?php
declare(strict_types=1);
namespace Piscina;

final class Coalizoes {
    public function __construct(private Galeria $g){}
    public function nft(int $id,int $owner): array {
        $n=$this->g->db->row('SELECT * FROM nfts WHERE id=? FOR UPDATE',[$id])??throw new Problem('NFT não encontrada.',404);
        if($n['state']!=='PRIVATE'||(int)$n['owner_id']!==$owner||$n['reserved_proposal']!==null||!$n['image_path'])throw new Problem('Use NFT privada, com imagem, do detentor atual e sem reserva. NFT da Piscina pública não é elegível.',409);
        return $n;
    }
    private function terms(int $actor,array $d,?array $old=null): array {
        $this->g->newBusiness();GaleriaInput::confirmed($d);if(($d['license_confirm']??false)!==true)throw new Problem('Confirme a autorização de exibição e histórico da imagem.');
        $frame=Domain::id($d['frame_id']??$old['frame_id']??null);$nft=Domain::id($d['nft_id']??$old['nft_id']??null);
        $from=GaleriaInput::date($d['starts_at']??null);$to=GaleriaInput::date($d['ends_at']??null);$expires=GaleriaInput::date($d['expires_at']??null);
        $limit=(new \DateTimeImmutable($this->g->now))->modify('+7 days')->format('Y-m-d H:i:s.u');
        if($from<=$this->g->now||$to<=$from||$expires<=$this->g->now||$expires>$limit||$expires>$from||(new \DateTimeImmutable($to))->getTimestamp()-(new \DateTimeImmutable($from))->getTimestamp()>31*86400)throw new Problem('Revise o intervalo futuro (até 31 dias) e a validade da proposta (até 7 dias e antes do início).');
        $f=(new Molduras($this->g))->operational($frame,$from,$to);$fu=(int)$f['holder_id'];$n=$this->g->db->row('SELECT owner_id FROM nfts WHERE id=?',[$nft]);$nu=(int)($n['owner_id']??0);
        if(!$fu||!$nu||$fu===$nu||!in_array($actor,[$fu,$nu],true))throw new Problem('Coalizão exige titular e detentor distintos; você precisa ser uma das partes.',403);
        if($old&&($frame!==(int)$old['frame_id']||$nft!==(int)$old['nft_id']||$fu!==(int)$old['frame_user']||$nu!==(int)$old['nft_user']))throw new Problem('Nova obra, Moldura ou contraparte exige outra proposta.',409);
        $frameUser=$this->g->user($fu);$nftUser=$this->g->user($nu);$n=$this->nft($nft,$nu);
        $policy=$this->g->db->row('SELECT * FROM galeria_policies WHERE id=?',[Domain::id($d['policy_id']??null)])??throw new Problem('Configure uma política de cotas antes da proposta.',409);
        if($policy['space_id']!==$f['space_id']||$policy['type_key']!==$f['type_key'])throw new Problem('Política não corresponde ao espaço/tipo.',409);$this->g->user((int)$policy['recipient_id']);
        $np=Exact::units($d['nft_percent']??null,2,true);$fp=Exact::units($d['frame_percent']??null,2,true);
        if(bcadd($np,$fp,0)!=='10000'||bccomp($np,'10000',0)>0)throw new Problem('Percentuais de 0 a 100%, duas casas, somando exatamente 100%.');
        $event=null;$eventRules=null;$eventPrize=null;
        if(!empty($d['event_id'])){$e=(new GaleriaEventos($this->g))->event(Domain::id($d['event_id']));if($e['state']!=='REGISTRATION'||$from>$e['voting_starts']||$to<$e['voting_ends'])throw new Problem('Inscrição fechada ou exposição não cobre a votação.',409);$event=$e['id'];$eventRules=GaleriaInput::decode($e['rules']);$eventPrize=['amount'=>$e['prize_amount'],'asset'=>$e['prize_asset'],'funds_state'=>$e['funds_state'],'title'=>$e['title']];}
        $location=$this->g->db->row('SELECT s.name space_name,s.address,p.name point_name FROM galeria_points p JOIN galeria_spaces s ON s.id=p.space_id WHERE p.id=?',[$f['point_id']]);
        return ['location'=>$location,'frame_id'=>(string)$frame,'nft_id'=>(string)$nft,'frame_user'=>(string)$fu,'nft_user'=>(string)$nu,'point_id'=>$f['point_id'],'space_id'=>$f['space_id'],
            'frame_user_name'=>$frameUser['display_name'],'nft_user_name'=>$nftUser['display_name'],'creator_name'=>$this->g->db->row('SELECT display_name FROM users WHERE id=?',[$n['creator_id']])['display_name'],'frame_name'=>$f['name'],'frame_authority'=>$f['authority_id'],'frame_version'=>$f['version'],'frame_metadata'=>GaleriaInput::decode($f['metadata']),
            'starts_at'=>$from,'ends_at'=>$to,'expires_at'=>$expires,'policy_id'=>$policy['id'],'policy'=>GaleriaInput::decode($policy['terms']),'x'=>$policy['x'],'total_shares'=>bcmul($policy['x'],'2',0),
            'recipient_id'=>$policy['recipient_id'],'nft_bps'=>(int)$np,'frame_bps'=>(int)$fp,'event_id'=>$event,'event_rules'=>$eventRules,'event_prize'=>$eventPrize,
            'license'=>'As partes declaram possuir autorização de exibição da imagem e de preservação do registro histórico desta exposição. Posse da NFT não substitui direitos autorais.',
            'obligations'=>GaleriaInput::text($d['obligations']??null,2000),'nft_title'=>$n['title'],'creator_id'=>$n['creator_id'],'image'=>NftImage::snapshot($n)];
    }
    public function proposal(int $id): array {return $this->g->db->row('SELECT p.*,v.terms,v.expires_at FROM galeria_proposals p JOIN galeria_proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.id=? FOR UPDATE',[$id])??throw new Problem('Proposta não encontrada.',404);}
    private function party(int $actor,array $p,array $d): void {
        $this->g->user($actor);if(!in_array((string)$actor,[$p['frame_user'],$p['nft_user']],true))throw new Problem('Proposta de terceiros.',403);
        if((string)($d['version']??'')!==$p['current_version'])throw new Problem('Versão alterada. Leia a contraproposta atual.',409);
    }
    private function version(int $id,int $v,int $actor,array $t): void {
        $this->g->db->run('INSERT INTO galeria_proposal_versions(proposal_id,version,author_id,terms,expires_at,created_at) VALUES(?,?,?,?,?,?)',[$id,$v,$actor,GaleriaInput::json($t),$t['expires_at'],$this->g->now]);
        $this->g->db->run('INSERT INTO galeria_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$id,$v,$actor,$this->g->now]);
    }
    public function preview(int $actor,array $d): array {
        $old=null;if(!empty($d['proposal_id'])){$old=$this->proposal(Domain::id($d['proposal_id']));$this->party($actor,$old,$d);}
        $t=$this->terms($actor,$d,$old);return ['terms'=>GaleriaQueries::terms($t),'preview_hash'=>hash('sha256',Domain::canonical($t))];
    }
    private function confirmedPreview(array $d,array $t): void {
        if(!is_string($d['preview_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['preview_hash']))throw new Problem('Revise o resumo atualizado antes de enviar o aceite da proposta.',409);
    }
    public function propose(int $actor,array $d): array {
        $this->g->user($actor);if((int)$this->g->db->row("SELECT COUNT(*) n FROM galeria_proposals WHERE state='PENDING' AND (frame_user=? OR nft_user=?)",[$actor,$actor])['n']>=50)throw new Problem('Limite de 50 propostas pendentes. Encerre ou aguarde expiração.',409);$t=$this->terms($actor,$d);$this->confirmedPreview($d,$t);$this->g->db->run('INSERT INTO galeria_proposals(frame_id,nft_id,frame_user,nft_user,created_at) VALUES(?,?,?,?,?)',[$t['frame_id'],$t['nft_id'],$t['frame_user'],$t['nft_user'],$this->g->now]);$id=(int)$this->g->db->pdo->lastInsertId();$this->version($id,1,$actor,$t);
        $other=(int)$t['frame_user']===$actor?(int)$t['nft_user']:(int)$t['frame_user'];$this->g->notify($other,"Galeria: proposta #$id aguardando revisão integral dos termos.");return ['proposal_id'=>(string)$id,'version'=>'1','state'=>'PENDING'];
    }
    public function counter(int $actor,array $d): array {
        $p=$this->proposal(Domain::id($d['proposal_id']??null));$this->party($actor,$p,$d);if($p['state']!=='PENDING'||$p['expires_at']<=$this->g->now)throw new Problem('Proposta encerrada ou expirada.',409);
        $t=$this->terms($actor,$d,$p);$this->confirmedPreview($d,$t);$v=(int)$p['current_version']+1;if($v>100)throw new Problem('Limite de 100 versões: encerre e crie outra proposta.',409);$this->version((int)$p['id'],$v,$actor,$t);$this->g->db->run('UPDATE galeria_proposals SET current_version=? WHERE id=?',[$v,$p['id']]);return ['proposal_id'=>$p['id'],'version'=>(string)$v,'state'=>'PENDING'];
    }
    public function accept(int $actor,array $d): array {
        $this->g->newBusiness();GaleriaInput::confirmed($d);if(($d['license_confirm']??false)!==true)throw new Problem('Confirme a autorização de uso da imagem.');
        $p=$this->proposal(Domain::id($d['proposal_id']??null));$this->party($actor,$p,$d);$t=GaleriaInput::decode($p['terms']);
        if(!is_string($d['terms_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['terms_hash']))throw new Problem('O resumo dos termos mudou. Consulte novamente.',409);
        if($p['state']==='ACTIVE')return ['agreement_id'=>$this->g->db->row('SELECT id FROM galeria_agreements WHERE proposal_id=?',[$p['id']])['id'],'state'=>'ACTIVE'];
        if($p['state']!=='PENDING'||$p['expires_at']<=$this->g->now||$t['starts_at']<=$this->g->now)throw new Problem('Proposta encerrada ou expirada.',409);
        $this->g->user((int)$p['frame_user']);$this->g->user((int)$p['nft_user']);$this->g->user((int)$t['recipient_id']);
        $f=(new Molduras($this->g))->operational((int)$p['frame_id'],$t['starts_at'],$t['ends_at']);$n=$this->nft((int)$p['nft_id'],(int)$p['nft_user']);
        if($f['holder_id']!==$p['frame_user']||$f['version']!==$t['frame_version']||$f['point_id']!==$t['point_id']||NftImage::version($n)!==$t['image']['version'])throw new Problem('Autoridade, local ou mídia alterada. Renegocie a proposta.',409);
        if($t['event_id']!==null){$e=(new GaleriaEventos($this->g))->event((int)$t['event_id']);if($e['state']!=='REGISTRATION'||$e['voting_starts']<=$this->g->now)throw new Problem('Evento já fechou inscrições.',409);if((int)$this->g->db->row("SELECT COUNT(*) n FROM galeria_candidates WHERE event_id=? AND state='VALID'",[$e['id']])['n']>=(int)$e['capacity'])throw new Problem('Capacidade do evento esgotada.',409);if($this->g->db->row('SELECT id FROM galeria_candidates WHERE event_id=? AND frame_id=?',[$e['id'],$p['frame_id']]))throw new Problem('Esta Moldura já participou da inscrição no evento.',409);}
        $this->g->db->run('INSERT IGNORE INTO galeria_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$p['id'],$p['current_version'],$actor,$this->g->now]);
        if((int)$this->g->db->row('SELECT COUNT(*) n FROM galeria_acceptances WHERE proposal_id=? AND version=?',[$p['id'],$p['current_version']])['n']<2)return ['proposal_id'=>$p['id'],'state'=>'PENDING'];
        if($this->g->db->row("SELECT id FROM galeria_agreements WHERE state IN ('RESERVED','EXHIBITING','SUSPENDED') AND (frame_id=? OR point_id=? OR nft_id=?) AND starts_at<? AND ends_at>? LIMIT 1",[$f['id'],$t['point_id'],$p['nft_id'],$t['ends_at'],$t['starts_at']]))throw new Problem('Moldura, ponto ou NFT já reservado nesse intervalo.',409);
        $this->g->db->run('INSERT INTO galeria_agreements(proposal_id,version,frame_id,nft_id,point_id,space_id,frame_user,nft_user,recipient_id,event_id,starts_at,ends_at,x,nft_bps,policy_id,terms,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$p['id'],$p['current_version'],$p['frame_id'],$p['nft_id'],$t['point_id'],$t['space_id'],$p['frame_user'],$p['nft_user'],$t['recipient_id'],$t['event_id'],$t['starts_at'],$t['ends_at'],$t['x'],$t['nft_bps'],$t['policy_id'],$p['terms'],$this->g->now]);
        $id=(int)$this->g->db->pdo->lastInsertId();$a=$this->agreement($id);$this->g->finance->hold($a);
        $this->g->db->run("UPDATE galeria_proposals SET state='ACTIVE' WHERE id=?",[$p['id']]);$this->g->db->run('UPDATE galeria_settings SET has_commitments=1 WHERE id=1');
        if($t['event_id']!==null)$this->g->db->run('INSERT INTO galeria_candidates(event_id,agreement_id,frame_id) VALUES(?,?,?)',[$t['event_id'],$id,$p['frame_id']]);
        foreach([$p['frame_user'],$p['nft_user']] as $u)$this->g->notify((int)$u,"Galeria: acordo #$id ativado; {$t['x']} cotas por parte em custódia operacional.");
        return ['agreement_id'=>(string)$id,'proposal_id'=>$p['id'],'state'=>'ACTIVE','x'=>$t['x'],'total_shares'=>$t['total_shares']];
    }
    public function reject(int $actor,array $d): array {
        GaleriaInput::confirmed($d);$p=$this->proposal(Domain::id($d['proposal_id']??null));$this->party($actor,$p,$d);if($p['state']!=='PENDING')throw new Problem('Proposta não está pendente.',409);$this->g->db->run("UPDATE galeria_proposals SET state='REJECTED' WHERE id=?",[$p['id']]);return ['proposal_id'=>$p['id'],'state'=>'REJECTED'];
    }
    public function agreement(int $id): array {return $this->g->db->row('SELECT * FROM galeria_agreements WHERE id=? FOR UPDATE',[$id])??throw new Problem('Exposição não encontrada.',404);}
    public function validAuthority(array $a): bool {
        try{$this->g->user((int)$a['frame_user']);$this->g->user((int)$a['nft_user']);$f=(new Molduras($this->g))->operational((int)$a['frame_id'],$a['starts_at'],$a['ends_at']);$n=$this->nft((int)$a['nft_id'],(int)$a['nft_user']);$t=GaleriaInput::decode($a['terms']);return $f['holder_id']===$a['frame_user']&&$f['point_id']===$a['point_id']&&$f['version']===$t['frame_version']&&NftImage::version($n)===$t['image']['version'];}catch(Problem){return false;}
    }
    public function refund(array $a,string $reason,bool $cascade=true): void {
        if($a['funds_state']==='CAPTURED')throw new Problem('Prestação já capturada; registre conciliação explícita, sem débito automático.',409);
        if($a['funds_state']==='RETURNED')return;
        $this->g->finance->finish($a,true);$this->g->db->run("UPDATE galeria_agreements SET state='CANCELLED',cancel_requested_by=NULL WHERE id=?",[$a['id']]);
        $this->g->db->run("UPDATE galeria_candidates SET state='INVALID' WHERE agreement_id=?",[$a['id']]);$this->g->incident((int)$a['id'],$a['event_id']?(int)$a['event_id']:null,$reason);
        if($cascade&&$a['event_id']!==null){$e=(new GaleriaEventos($this->g))->event((int)$a['event_id']);if(in_array($e['state'],['PREPARED','VOTING','REVIEW','SUSPENDED'],true))(new GaleriaEventos($this->g))->cancelInternal($e,'Candidatura inviável: '.$reason);}
    }
    public function cancel(int $actor,array $d): array {
        $this->g->user($actor);GaleriaInput::confirmed($d);$a=$this->agreement(Domain::id($d['agreement_id']??null));if(!in_array((string)$actor,[$a['frame_user'],$a['nft_user']],true))throw new Problem('Exposição de terceiros.',403);
        if($a['state']==='CANCELLED')return ['agreement_id'=>$a['id'],'state'=>'CANCELLED'];
        if($a['starts_at']<=$this->g->now||$a['funds_state']!=='HELD')throw new Problem('Após o início, solicite resolução motivada à Administração.',409);
        if($a['cancel_requested_by']===null){$this->g->db->run('UPDATE galeria_agreements SET cancel_requested_by=? WHERE id=?',[$actor,$a['id']]);return ['agreement_id'=>$a['id'],'state'=>'CANCELLATION_REQUESTED'];}
        if((int)$a['cancel_requested_by']===$actor)return ['agreement_id'=>$a['id'],'state'=>'CANCELLATION_REQUESTED'];
        $this->refund($a,'Cancelamento consensual anterior ao início');return ['agreement_id'=>$a['id'],'state'=>'CANCELLED'];
    }
    public function physical(int $actor,array $d): array {
        $this->g->admin($actor,$d);$a=$this->agreement(Domain::id($d['agreement_id']??null));if(!in_array($a['state'],['RESERVED','EXHIBITING'],true)||$this->g->now>=$a['ends_at']||!$this->validAuthority($a))throw new Problem('Confirme a infraestrutura e autorização durante a vigência da exposição.',409);
        $this->g->db->run('UPDATE galeria_agreements SET physical_at=? WHERE id=?',[$this->g->now,$a['id']]);return ['agreement_id'=>$a['id'],'physical_confirmed'=>true];
    }
    public function complete(int $actor,array $d): array {
        $this->g->admin($actor,$d);$a=$this->agreement(Domain::id($d['agreement_id']??null));if($a['state']==='COMPLETED')return ['agreement_id'=>$a['id'],'state'=>'COMPLETED'];
        if(!in_array($a['state'],['RESERVED','EXHIBITING'],true)||$a['ends_at']>$this->g->now||!$a['physical_at'])throw new Problem('Exposição precisa ter encerrado, com infraestrutura confirmada e sem incidente pendente.',409);
        $this->g->db->run("UPDATE galeria_agreements SET state='COMPLETED',completed_at=? WHERE id=?",[$this->g->now,$a['id']]);
        if($a['event_id']===null)$this->g->finance->finish($a,false);
        return ['agreement_id'=>$a['id'],'state'=>'COMPLETED','capture'=>$a['event_id']===null?'CAPTURED':'AFTER_DEFINITIVE_EVENT'];
    }
    public function suspend(array $a,string $reason): void {
        if(!in_array($a['state'],['RESERVED','EXHIBITING'],true))return;
        $deadline=(new \DateTimeImmutable($this->g->now))->modify('+24 hours')->format('Y-m-d H:i:s.u');
        $this->g->db->run("UPDATE galeria_agreements SET state='SUSPENDED',resolution_until=? WHERE id=?",[$deadline,$a['id']]);$this->g->incident((int)$a['id'],$a['event_id']?(int)$a['event_id']:null,$reason);
        if($a['event_id']!==null)(new GaleriaEventos($this->g))->suspend((int)$a['event_id'],$reason);
    }
    public function suspendCommand(int $actor,array $d): array {$reason=$this->g->admin($actor,$d);$a=$this->agreement(Domain::id($d['agreement_id']??null));$this->suspend($a,$reason);return ['agreement_id'=>$a['id'],'state'=>'SUSPENDED'];}
    public function resolve(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$a=$this->agreement(Domain::id($d['agreement_id']??null));if($a['state']!=='SUSPENDED')throw new Problem('Exposição não está suspensa.',409);
        if(($d['resolution']??'')==='refund'){$this->refund($a,$reason);$state='CANCELLED';}
        elseif(($d['resolution']??'')==='resume'){
            if($this->g->now>=$a['ends_at']||$this->g->now>=$a['resolution_until']||!$this->validAuthority($a))throw new Problem('Autoridade original não restabelecida no prazo. Cancele e devolva integralmente.',409);
            $state=$this->g->now>=$a['starts_at']?'EXHIBITING':'RESERVED';$this->g->db->run('UPDATE galeria_agreements SET state=?,resolution_until=NULL WHERE id=?',[$state,$a['id']]);
        }else throw new Problem('Escolha retomar ou devolver integralmente.');
        $this->g->db->run('UPDATE galeria_incidents SET resolution=?,resolved_at=? WHERE agreement_id=? AND resolved_at IS NULL',[$reason,$this->g->now,$a['id']]);return ['agreement_id'=>$a['id'],'state'=>$state];
    }
}
