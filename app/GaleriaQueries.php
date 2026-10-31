<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaQueries {
    private Galeria $g;
    public function __construct(private Domain $domain){$this->g=new Galeria($domain);}
    private function offset(array $q): int {return max(0,min(100000,(int)($q['offset']??0)));}
    private function page(string $sql,array $params,array $q): array {$off=$this->offset($q);$rows=$this->g->db->all($sql.' LIMIT 25 OFFSET '.$off,$params);return ['rows'=>array_slice($rows,0,24),'offset'=>$off,'has_more'=>count($rows)>24];}
    public static function terms(array $t): array {unset($t['image']['image_path'],$t['image']['thumbnail_path']);return $t;}
    public function status(): array {
        $installed=Galeria::installed($this->g->db);$s=$installed?$this->g->db->row('SELECT accepting,has_commitments FROM galeria_settings WHERE id=1'):['accepting'=>'0','has_commitments'=>'0'];
        return ['installed'=>$installed,'enabled'=>$this->g->enabled(),'accepting'=>$this->g->enabled()&&$s['accepting']==='1','has_commitments'=>$s['has_commitments']==='1'];
    }
    private function installed(): void {if(!Galeria::installed($this->g->db))throw new Problem('Galeria aguardando instalação da migração 004.',409,'galeria_pending');}
    public function catalog(array $q): array {
        $status=$this->status();if(!$status['installed'])return $status+['rows'=>[],'spaces'=>[],'offset'=>0,'has_more'=>false];
        $where=["f.status IN ('ACTIVE','ARCHIVED')"];$params=[];
        if(!$status['enabled'])$where[]="EXISTS(SELECT 1 FROM galeria_agreements a WHERE a.frame_id=f.id)";
        if(!empty($q['q'])){$where[]='(f.name LIKE ? OR f.code LIKE ?)';$search='%'.mb_substr((string)$q['q'],0,80).'%';$params[]=$search;$params[]=$search;}
        if(!empty($q['space_id'])){$where[]='p.space_id=?';$params[]=Domain::id($q['space_id']);}
        if(!empty($q['mine']))throw new Problem('Use a consulta autenticada de suas Molduras.');
        $page=$this->page("SELECT f.id,f.code,f.authority_id,f.name,f.type_key,f.holder_id,f.status,f.metadata,u.display_name holder_name,p.name point_name,p.space_id,s.name space_name,s.address,o.id offer_id,o.version offer_version,o.amount,o.asset,o.seller_id,o.terms offer_terms,o.expires_at,
            (SELECT id FROM galeria_photos ph WHERE ph.frame_id=f.id ORDER BY id DESC LIMIT 1) photo_id,
            EXISTS(SELECT 1 FROM galeria_agreements a WHERE a.frame_id=f.id AND a.state IN ('RESERVED','EXHIBITING','SUSPENDED')) busy
            FROM galeria_frames f LEFT JOIN users u ON u.id=f.holder_id LEFT JOIN galeria_allocations l ON l.id=(SELECT id FROM galeria_allocations WHERE frame_id=f.id AND ends_at>UTC_TIMESTAMP(6) ORDER BY starts_at LIMIT 1)
            LEFT JOIN galeria_points p ON p.id=l.point_id LEFT JOIN galeria_spaces s ON s.id=p.space_id LEFT JOIN galeria_offers o ON o.frame_id=f.id AND o.state='ACTIVE' AND o.buyer_id IS NULL AND o.expires_at>UTC_TIMESTAMP(6) WHERE ".implode(' AND ',$where).' ORDER BY f.id DESC',$params,$q);
        foreach($page['rows'] as &$r){$r['metadata']=GaleriaInput::decode($r['metadata']);if(!$status['accepting']){$r['checkout_disabled']=true;}}unset($r);
        return $status+$page+['spaces'=>$this->g->db->all("SELECT id,name FROM galeria_spaces WHERE status<>'ARCHIVED' ORDER BY name LIMIT 200")];
    }
    public function frame(int $id,?int $viewer=null,array $q=[]): array {
        $this->installed();$f=$this->g->db->row('SELECT f.*,u.display_name holder_name FROM galeria_frames f LEFT JOIN users u ON u.id=f.holder_id WHERE f.id=?',[$id])??throw new Problem('Moldura não encontrada.',404);
        $admin=false;if($viewer){$u=$this->domain->user($viewer);$admin=$u['role']==='admin';}
        if($f['status']==='DRAFT'&&!$admin)throw new Problem('Moldura não encontrada.',404);
        $f['metadata']=GaleriaInput::decode($f['metadata']);$f['photos']=$this->g->db->all('SELECT id,sha FROM galeria_photos WHERE frame_id=? ORDER BY id',[$id]);
        $f['allocations']=$this->g->db->all('SELECT l.id,l.starts_at,l.ends_at,p.id point_id,p.name point_name,p.space_id,p.authorized_until,p.opens,p.closes,p.weekdays,p.conditions,s.name space_name,s.address FROM galeria_allocations l JOIN galeria_points p ON p.id=l.point_id JOIN galeria_spaces s ON s.id=p.space_id WHERE l.frame_id=? ORDER BY l.starts_at DESC LIMIT 48',[$id]);
        $f['current_event']=$this->g->db->row("SELECT e.id FROM galeria_candidates c JOIN galeria_events e ON e.id=c.event_id WHERE c.frame_id=? AND c.state='VALID' AND e.state IN ('REGISTRATION','PREPARED','VOTING','REVIEW','FINALIZED','SETTLED') ORDER BY CASE e.state WHEN 'VOTING' THEN 0 WHEN 'PREPARED' THEN 1 WHEN 'REGISTRATION' THEN 2 ELSE 3 END,e.id DESC LIMIT 1",[$id]);
        $f['offers']=$this->g->db->all("SELECT id,seller_id,asset,amount,version,terms,expires_at FROM galeria_offers WHERE frame_id=? AND buyer_id IS NULL AND state='ACTIVE' AND expires_at>UTC_TIMESTAMP(6)",[$id]);
        $f['agenda']=$this->g->db->all("SELECT id,starts_at,ends_at,state,event_id FROM galeria_agreements WHERE frame_id=? AND state IN ('RESERVED','EXHIBITING','SUSPENDED') ORDER BY starts_at LIMIT 100",[$id]);
        $f['blocks']=$this->g->db->all('SELECT starts_at,ends_at'.($admin?',reason':'').' FROM galeria_blocks WHERE frame_id=? ORDER BY starts_at DESC LIMIT 48',[$id]);
        $historyOffset=$this->offset($q);$f['offset']=$historyOffset;
        $f['history']=$this->g->db->all("SELECT a.id,a.nft_id,a.starts_at,a.ends_at,a.state,a.event_id,a.space_id,a.terms FROM galeria_agreements a WHERE a.frame_id=? AND a.state='COMPLETED' ORDER BY a.id DESC LIMIT 25 OFFSET $historyOffset",[$id]);$f['history_has_more']=count($f['history'])>24;$f['history']=array_slice($f['history'],0,24);
        foreach($f['history'] as &$h){$t=GaleriaInput::decode($h['terms']);$h['nft_title']=$t['nft_title'];$h['creator_id']=$t['creator_id'];$h['frame_user']=$t['frame_user'];$h['nft_user']=$t['nft_user'];unset($h['terms']);}unset($h);
        $f['policies']=$this->g->db->all('SELECT gp.id,gp.x,gp.recipient_id,u.display_name recipient_name FROM galeria_policies gp JOIN users u ON u.id=gp.recipient_id WHERE gp.type_key=? AND gp.space_id IN (SELECT p.space_id FROM galeria_allocations l JOIN galeria_points p ON p.id=l.point_id WHERE l.frame_id=? AND l.ends_at>UTC_TIMESTAMP(6)) ORDER BY gp.id DESC LIMIT 24',[$f['type_key'],$id]);
        $f['ownership']=$this->g->db->all('SELECT h.user_id,u.display_name,h.starts_at,h.ends_at FROM galeria_ownership h JOIN users u ON u.id=h.user_id WHERE h.frame_id=? ORDER BY h.id DESC LIMIT 24',[$id]);return $f;
    }
    public function proposal(int $actor,int $id): array {
        $this->installed();$this->domain->user($actor);$p=$this->g->db->row('SELECT * FROM galeria_proposals WHERE id=?',[$id])??throw new Problem('Proposta não encontrada.',404);
        if(!in_array((string)$actor,[$p['frame_user'],$p['nft_user']],true)&&$this->domain->user($actor)['role']!=='admin')throw new Problem('Proposta de terceiros.',403);
        $p['versions']=$this->g->db->all('SELECT version,author_id,terms,expires_at,created_at FROM galeria_proposal_versions WHERE proposal_id=? ORDER BY version DESC LIMIT 100',[$id]);
        foreach($p['versions'] as &$v){$t=GaleriaInput::decode($v['terms']);$v['terms_hash']=hash('sha256',Domain::canonical($t));$v['terms']=self::terms($t);}unset($v);
        $p['acceptances']=$this->g->db->all('SELECT version,user_id,created_at FROM galeria_acceptances WHERE proposal_id=? ORDER BY version,user_id',[$id]);return $p;
    }
    public function mine(int $actor,array $q): array {
        $this->installed();$this->domain->user($actor);$off=$this->offset($q);
        $frames=$this->page('SELECT id,name,code,status,authority_id FROM galeria_frames WHERE holder_id=? ORDER BY id DESC',[$actor],$q);
        $proposals=$this->page('SELECT p.*,f.name frame_name,n.title nft_title FROM galeria_proposals p JOIN galeria_frames f ON f.id=p.frame_id JOIN nfts n ON n.id=p.nft_id WHERE (frame_user=? OR nft_user=?) ORDER BY p.id DESC',[$actor,$actor],$q);
        $agreements=$this->page('SELECT a.*,f.name frame_name FROM galeria_agreements a JOIN galeria_frames f ON f.id=a.frame_id WHERE a.frame_user=? OR a.nft_user=? ORDER BY a.id DESC',[$actor,$actor],$q);
        foreach($agreements['rows'] as &$a)$a['terms']=self::terms(GaleriaInput::decode($a['terms']));unset($a);
        $finance=$this->page('SELECT gf.id,gf.operation_id,gf.entity_type,gf.entity_id,gf.asset,gf.amount,lt.kind,gf.created_at,a.user_id from_user,a.purpose from_purpose,b.user_id to_user,b.purpose to_purpose FROM galeria_finance gf JOIN ledger_transactions lt ON lt.id=gf.transaction_id JOIN accounts a ON a.id=gf.from_account JOIN accounts b ON b.id=gf.to_account WHERE a.user_id=? OR b.user_id=? ORDER BY gf.id DESC',[$actor,$actor],$q);
        $nfts=$this->page("SELECT id,title,creator_id FROM nfts WHERE owner_id=? AND state='PRIVATE' AND reserved_proposal IS NULL AND image_path IS NOT NULL ORDER BY id DESC",[$actor],$q);
        $trades=$this->page('SELECT p.*,f.name frame_name FROM galeria_trade_proposals p JOIN galeria_frames f ON f.id=p.frame_id WHERE p.buyer_id=? OR p.seller_id=? ORDER BY p.id DESC',[$actor,$actor],$q);
        return ['trades'=>$trades,'frames'=>$frames,'proposals'=>$proposals,'agreements'=>$agreements,'finance'=>$finance,'nfts'=>$nfts,'balances'=>$this->domain->ledger->balances($actor),'status'=>$this->status(),'offset'=>$off];
    }
    public function events(array $q,bool $admin=false): array {
        $this->installed();return $this->page('SELECT id,title,state,capacity,voting_starts,voting_ends,review_hours,prize_asset,prize_amount,funds_state,closed_at,result_version FROM galeria_events '.($admin?'':"WHERE state<>'DRAFT' ").'ORDER BY id DESC',[],$q);
    }
    public function event(int $id,?int $viewer=null,array $q=[]): array {
        $this->installed();$e=$this->g->db->row('SELECT * FROM galeria_events WHERE id=?',[$id])??throw new Problem('Evento não encontrado.',404);$admin=false;
        if($viewer)$admin=$this->domain->user($viewer)['role']==='admin';if($e['state']==='DRAFT'&&!$admin)throw new Problem('Evento não encontrado.',404);
        $e['rules']=GaleriaInput::decode($e['rules']);unset($e['source_account']);
        $e['candidates']=$this->g->db->all("SELECT c.id,c.frame_id,c.state,c.snapshot,a.frame_user,a.nft_user,a.nft_id,a.state agreement_state,a.physical_at,a.id agreement_id,a.terms FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.event_id=? AND c.state='VALID' ORDER BY c.id",[$id]);
        foreach($e['candidates'] as &$r){$t=GaleriaInput::decode($r['snapshot']??$r['terms']);$r['snapshot']=$admin?self::terms($t):array_intersect_key(self::terms($t),array_flip(['frame_id','frame_name','frame_authority','nft_id','nft_title','creator_id','creator_name','frame_user','nft_user','frame_user_name','nft_user_name','starts_at','ends_at','credits','location','prize_asset','prize_amount']));unset($r['terms']);}unset($r);
        $e['result']=null;if($e['result_version']!=='0'&&(in_array($e['state'],['FINALIZED','SETTLED'],true)||$admin)){$r=$this->g->db->row('SELECT data FROM galeria_results WHERE event_id=? AND version=?',[$id,$e['result_version']]);if($r)$e['result']=GaleriaInput::decode($r['data']);}
        $e['eligible']=false;$e['has_voted']=false;
        if($viewer){$e['eligible']=(bool)$this->g->db->row('SELECT user_id FROM galeria_electors WHERE event_id=? AND user_id=? AND active=1',[$id,$viewer]);$e['has_voted']=(bool)$this->g->db->row('SELECT id FROM galeria_votes WHERE event_id=? AND user_id=?',[$id,$viewer]);foreach($e['candidates'] as $c)if(in_array((string)$viewer,[$c['frame_user'],$c['nft_user']],true))$e['eligible']=false;}
        if($admin){$e['electors']=$this->g->db->all('SELECT user_id,active FROM galeria_electors WHERE event_id=? ORDER BY user_id LIMIT 500',[$id]);$vo=$this->offset($q);$e['votes_offset']=$vo;$e['votes']=$this->g->db->all('SELECT id,created_at,invalidated_at,reason FROM galeria_votes WHERE event_id=? ORDER BY id LIMIT 25 OFFSET '.$vo,[$id]);$e['votes_has_more']=count($e['votes'])>24;$e['votes']=array_slice($e['votes'],0,24);$e['incidents']=$this->g->db->all('SELECT id,reason,resolution,created_at,resolved_at FROM galeria_incidents WHERE event_id=? ORDER BY id DESC LIMIT 100',[$id]);}
        return $e;
    }
    public function admin(int $actor,array $q): array {
        $this->domain->admin($actor);$this->installed();$section=$q['section']??'overview';$base=['status'=>$this->status(),'section'=>$section];
        $base['spaces']=$this->g->db->all('SELECT * FROM galeria_spaces ORDER BY id LIMIT 200');$base['points']=$this->g->db->all('SELECT * FROM galeria_points ORDER BY id LIMIT 500');
        $table=match($section){'frames'=>'galeria_frames','policies'=>'galeria_policies','contracts'=>'galeria_agreements','proposals'=>'galeria_proposals','trades'=>'galeria_trade_proposals','sales'=>'galeria_sales','incidents'=>'galeria_incidents',default=>null};
        if($table){$base['page']=$this->page("SELECT * FROM $table ORDER BY id DESC",[],$q);foreach($base['page']['rows'] as &$r){if(isset($r['terms']))$r['terms']=self::terms(GaleriaInput::decode($r['terms']));if(isset($r['metadata']))$r['metadata']=GaleriaInput::decode($r['metadata']);}unset($r);}
        elseif($section==='events')$base['page']=$this->events($q,true);
        elseif($section==='audit')$base['page']=$this->page("SELECT id,actor_id,kind,data,created_at FROM audit_events WHERE kind LIKE 'galeria.%' OR kind LIKE 'admin.galeria.%' ORDER BY id DESC",[],$q);
        else{
            $base['counts']=$this->g->db->all('SELECT status state,COUNT(*) n FROM galeria_frames GROUP BY status');$base['agreements']=$this->g->db->all('SELECT state,COUNT(*) n FROM galeria_agreements GROUP BY state');$base['proposals']=$this->g->db->row('SELECT COUNT(*) sent,SUM(state=\'ACTIVE\') activated FROM galeria_proposals');
            $base['income']=$this->g->db->all("SELECT asset,balance FROM accounts WHERE purpose='galeria_revenue'");$base['occupancy']=(new GaleriaAvailability($this->g))->read($q);$base['occupancy_note']=$base['occupancy']?'Horas confirmadas sobre horas operacionais registradas, no período indicado.':'Não disponível: ainda não há horas operacionais registradas suficientes no período.';
            $base['response_time']=$this->g->db->row('SELECT COUNT(*) samples,AVG(TIMESTAMPDIFF(SECOND,v.created_at,a.created_at)) seconds FROM galeria_agreements a JOIN galeria_proposal_versions v ON v.proposal_id=a.proposal_id AND v.version=a.version');
            $base['reconciliation']=self::reconcile($this->g->db);$base['events']=$this->events([],true)['rows'];
        }return $base;
    }
    public static function reconcile(Database $db): array {
        if(!Galeria::installed($db))return [];
        $issues=[];$expected=[];foreach($db->all("SELECT frame_user,nft_user,x FROM galeria_agreements WHERE funds_state='HELD'") as $a)foreach(['frame_user','nft_user'] as $k)$expected[$a[$k]]=bcadd($expected[$a[$k]]??'0',$a['x'],0);
        foreach($db->all("SELECT user_id,balance FROM accounts WHERE asset='SHARE' AND purpose='galeria_hold'") as $a){if($a['balance']!==($expected[$a['user_id']]??'0'))$issues[]=['kind'=>'SHARE_HOLD','user_id'=>$a['user_id']];unset($expected[$a['user_id']]);}
        foreach($expected as $u=>$n)if($n!=='0')$issues[]=['kind'=>'MISSING_HOLD','user_id'=>$u];
        foreach($db->all("SELECT e.id,e.prize_asset,e.prize_amount,e.funds_state,COALESCE(a.balance,0) balance FROM galeria_events e LEFT JOIN accounts a ON a.code=CONCAT('T:',e.prize_asset,':galeria_prize:',e.id)") as $e)if($e['balance']!==($e['funds_state']==='FUNDED'?$e['prize_amount']:'0'))$issues[]=['kind'=>'PRIZE','event_id'=>$e['id']];
        return $issues;
    }
}
