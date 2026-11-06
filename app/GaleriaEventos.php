<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaEventos {
    public function __construct(private Galeria $g){}
    public function event(int $id): array {return $this->g->db->row('SELECT * FROM galeria_events WHERE id=? FOR UPDATE',[$id])??throw new Problem('Evento não encontrado.',404);}
    public function save(int $actor,array $d): array {
        $this->g->admin($actor,$d);$title=GaleriaInput::text($d['title']??null,120);
        $start=GaleriaInput::date($d['voting_starts']??null);$end=GaleriaInput::date($d['voting_ends']??null);if($start<=$this->g->now||$end<=$start||(new \DateTimeImmutable($end))->getTimestamp()-(new \DateTimeImmutable($start))->getTimestamp()>31*86400)throw new Problem('Janela de votação futura e com até 31 dias.');
        $capacity=GaleriaInput::integer($d['capacity']??4,2,32);$hours=GaleriaInput::integer($d['review_hours']??24,0,168);$resolution=GaleriaInput::integer($d['resolution_hours']??24,1,168);
        $asset=$d['prize_asset']??'';$amount=GaleriaInput::money($d['prize']??null,$asset);
        $rules=['version'=>1,'capacity'=>$capacity,'minimum'=>$capacity,'vote'=>'ONE_CREDENTIALED_ACCOUNT_ONE_VOTE','interested_parties'=>'EXCLUDED','ties'=>'SHARED','zero_votes'=>'NO_WINNER_PRIZE_RETURNED','review_hours'=>$hours,'reopen_before_payment'=>'MOTIVATED_NEW_REVIEW_SAME_DURATION','resolution_hours'=>$resolution,'rounding'=>'WINNER_ID_ASC_THEN_NFT_FLOOR_FRAME_REMAINDER','share_refunds'=>'FULL_IF_CANCELLED_BEFORE_DEFINITIVE_RESULT','capture'=>'COMPLETION_AND_DEFINITIVE_EVENT','description'=>GaleriaInput::text($d['rules']??null,4000)];
        $args=[$title,$capacity,$start,$end,$hours,$resolution,$asset,$amount,GaleriaInput::json($rules)];
        if(!empty($d['event_id'])){$e=$this->event(Domain::id($d['event_id']));if($e['state']!=='DRAFT'||$e['funds_state']!=='UNFUNDED')throw new Problem('Regulamento publicado é imutável. Crie outro evento.',409);$id=$e['id'];$this->g->db->run('UPDATE galeria_events SET title=?,capacity=?,voting_starts=?,voting_ends=?,review_hours=?,resolution_hours=?,prize_asset=?,prize_amount=?,rules=? WHERE id=?',[...$args,$id]);}
        else{$this->g->db->run('INSERT INTO galeria_events(title,capacity,voting_starts,voting_ends,review_hours,resolution_hours,prize_asset,prize_amount,rules,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[...$args,$actor,$this->g->now]);$id=$this->g->db->pdo->lastInsertId();}
        return ['event_id'=>(string)$id,'state'=>'DRAFT'];
    }
    public function publish(int $actor,array $d): array {
        $this->g->newBusiness();$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='DRAFT'||$e['voting_starts']<=$this->g->now)throw new Problem('Evento precisa estar em rascunho e no futuro.',409);
        $this->g->db->run("UPDATE galeria_events SET state='REGISTRATION',published_at=? WHERE id=?",[$this->g->now,$e['id']]);return ['event_id'=>$e['id'],'state'=>'REGISTRATION'];
    }
    public function fund(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['funds_state']==='FUNDED')return ['event_id'=>$e['id'],'state'=>'FUNDED'];
        if(!in_array($e['state'],['DRAFT','REGISTRATION'],true)||$e['funds_state']!=='UNFUNDED')throw new Problem('Evento não pode receber novo financiamento.',409);
        $source=$d['source']??'';
        $account=match($source){'wallet'=>$this->g->finance->userAccount($actor,$e['prize_asset']),'treasury'=>$this->g->domain->ledger->account(null,$e['prize_asset'],'treasury'),'galeria_revenue'=>$this->g->domain->ledger->account(null,$e['prize_asset'],'galeria_revenue'),default=>throw new Problem('Escolha carteira própria, tesouraria ou receitas da Galeria.')};
        $this->g->finance->move($account,$this->g->finance->prizeAccount($e),$e['prize_amount'],'GALERIA_PRIZE_FUND','event',(int)$e['id'],'fund');
        $this->g->db->run("UPDATE galeria_events SET funds_state='FUNDED',source_account=? WHERE id=?",[$account['id'],$e['id']]);return ['event_id'=>$e['id'],'state'=>'FUNDED','amount'=>$e['prize_amount'],'asset'=>$e['prize_asset']];
    }
    public function candidates(array $e): array {return $this->g->db->all("SELECT c.*,a.state agreement_state,a.physical_at,a.starts_at,a.ends_at,a.terms,a.frame_user,a.nft_user,a.nft_id,a.point_id,a.space_id,a.nft_bps,a.funds_state FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.event_id=? AND c.state='VALID' ORDER BY c.id",[$e['id']]);}
    private function ready(array $e,bool $completed=false): array {
        $rows=$this->candidates($e);if(count($rows)!==(int)$e['capacity'])throw new Problem('O evento exige todas as candidaturas válidas e Molduras distintas.',409);
        if($e['funds_state']!=='FUNDED'||$this->g->finance->prizeAccount($e)['balance']!==$e['prize_amount'])throw new Problem('Prêmio integralmente financiado é obrigatório.',409);
        $c=new Coalizoes($this->g);
        foreach($rows as $r){if(!$r['physical_at']||$r['starts_at']>$e['voting_starts']||$r['ends_at']<$e['voting_ends']||!in_array($r['agreement_state'],['RESERVED','EXHIBITING','COMPLETED'],true))throw new Problem('Confirme a infraestrutura e as exposições antes de avançar.',409);
            if($completed&&$r['agreement_state']!=='COMPLETED')throw new Problem('Confirme a prestação concluída de todas as exposições.',409);
            if($r['agreement_state']!=='COMPLETED'&&!$c->validAuthority($c->agreement((int)$r['agreement_id'])))throw new Problem('Autoridade de exposição alterada. Suspenda e resolva o evento.',409);
        }return $rows;
    }
    public function prepare(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='REGISTRATION'||$e['voting_starts']<=$this->g->now)throw new Problem('Prepare antes da janela de votação, durante inscrições.',409);
        $rows=$this->ready($e);
        foreach($rows as $r){$t=GaleriaInput::decode($r['terms']);$names=$this->g->db->all('SELECT id,display_name FROM users WHERE id IN (?,?,?) ORDER BY id',[$r['frame_user'],$r['nft_user'],$t['creator_id']]);$t['credits']=$names;$t['rules']=GaleriaInput::decode($e['rules']);$t['prize_asset']=$e['prize_asset'];$t['prize_amount']=$e['prize_amount'];$space=$this->g->db->row('SELECT s.name space_name,s.address,p.name point_name FROM galeria_points p JOIN galeria_spaces s ON s.id=p.space_id WHERE p.id=?',[$r['point_id']]);$t['location']=$space;$this->g->db->run('UPDATE galeria_candidates SET snapshot=? WHERE id=?',[GaleriaInput::json($t),$r['id']]);}
        $this->g->db->run("UPDATE galeria_events SET state='PREPARED' WHERE id=?",[$e['id']]);return ['event_id'=>$e['id'],'state'=>'PREPARED'];
    }
    public function open(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='PREPARED'||$this->g->now<$e['voting_starts']||$this->g->now>=$e['voting_ends'])throw new Problem('Abra apenas no intervalo publicado, após preparação.',409);$this->ready($e);
        $this->g->db->run("UPDATE galeria_events SET state='VOTING' WHERE id=?",[$e['id']]);return ['event_id'=>$e['id'],'state'=>'VOTING'];
    }
    public function credential(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if(!in_array($e['state'],['REGISTRATION','PREPARED','VOTING'],true)||$this->g->now>=$e['voting_ends'])throw new Problem('Credenciamento encerrado.',409);
        $u=Domain::id($d['user_id']??null);$this->g->user($u);$reference=GaleriaInput::text($d['credential_ref']??null,120,3);
        if($this->g->db->row("SELECT c.id FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.event_id=? AND c.state='VALID' AND (a.frame_user=? OR a.nft_user=?)",[$e['id'],$u,$u]))throw new Problem('Parte interessada não pode ser eleitora desse evento.',409);
        $hash=hash_hmac('sha256',$e['id'].':'.mb_strtolower($reference),CredentialKey::get($this->g->domain->config));
        if($this->g->db->row('SELECT user_id FROM galeria_electors WHERE event_id=? AND (credential_hash=? OR user_id=?)',[$e['id'],$hash,$u]))throw new Problem('Credencial ou conta já cadastrada nesse evento.',409);
        $this->g->db->run('INSERT INTO galeria_electors(event_id,user_id,credential_hash,created_at) VALUES(?,?,?,?)',[$e['id'],$u,$hash,$this->g->now]);return ['event_id'=>$e['id'],'user_id'=>(string)$u,'credentialed'=>true];
    }
    public function vote(int $actor,array $d): array {
        $this->g->user($actor);GaleriaInput::confirmed($d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='VOTING'||$this->g->now<$e['voting_starts']||$this->g->now>=$e['voting_ends'])throw new Problem('Votação indisponível ou encerrada.',409);
        $elector=$this->g->db->row('SELECT * FROM galeria_electors WHERE event_id=? AND user_id=? AND active=1',[$e['id'],$actor]);if(!$elector)throw new Problem('Seu credenciamento neste evento é necessário.',403);
        if($this->g->db->row("SELECT c.id FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.event_id=? AND c.state='VALID' AND (a.frame_user=? OR a.nft_user=?)",[$e['id'],$actor,$actor]))throw new Problem('Partes interessadas não votam neste evento.',403);
        $id=Domain::id($d['candidate_id']??null);if(!$this->g->db->row("SELECT id FROM galeria_candidates WHERE id=? AND event_id=? AND state='VALID' AND snapshot IS NOT NULL",[$id,$e['id']]))throw new Problem('Candidatura inválida.');
        $a=$this->g->db->row('SELECT a.* FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.id=?',[$id]);if(!(new Coalizoes($this->g))->validAuthority($a))throw new Problem('Autoridade de exibição indisponível; aguarde resolução administrativa.',409);
        if($this->g->db->row('SELECT id FROM galeria_votes WHERE event_id=? AND user_id=?',[$e['id'],$actor]))throw new Problem('Você já votou neste evento.',409);
        $this->g->db->run('INSERT INTO galeria_votes(event_id,user_id,candidate_id,created_at) VALUES(?,?,?,?)',[$e['id'],$actor,$id,$this->g->now]);return ['event_id'=>$e['id'],'state'=>'VOTE_RECORDED'];
    }
    private function tally(int $actor,array $e,string $reason): array {
        $rows=$this->g->db->all("SELECT c.id,c.frame_id,COUNT(v.id) votes FROM galeria_candidates c LEFT JOIN galeria_votes v ON v.candidate_id=c.id AND v.invalidated_at IS NULL WHERE c.event_id=? AND c.state='VALID' GROUP BY c.id,c.frame_id ORDER BY votes DESC,c.id",[$e['id']]);
        $max=(int)($rows[0]['votes']??0);$rank=0;$last=null;foreach($rows as &$r){$n=(int)$r['votes'];if($last!==$n){$rank++;$last=$n;}$r['position']=$rank;$r['winner']=$max>0&&$n===$max;}unset($r);
        $data=['version'=>(int)$e['result_version']+1,'valid_votes'=>array_sum(array_map(fn($r)=>(int)$r['votes'],$rows)),'candidates'=>$rows,'winners'=>array_values(array_column(array_filter($rows,fn($r)=>$r['winner']),'id')),'ranking_version'=>1];
        $this->g->db->run('INSERT INTO galeria_results(event_id,version,data,author_id,reason,created_at) VALUES(?,?,?,?,?,?)',[$e['id'],$data['version'],GaleriaInput::json($data),$actor,$reason,$this->g->now]);$this->g->db->run('UPDATE galeria_events SET result_version=? WHERE id=?',[$data['version'],$e['id']]);return $data;
    }
    public function close(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']==='REVIEW')return ['event_id'=>$e['id'],'state'=>'REVIEW'];
        if($e['state']!=='VOTING'||$this->g->now<$e['voting_ends'])throw new Problem('A votação só encerra após o horário publicado.',409);
        $r=$this->tally($actor,$e,$reason);$until=(new \DateTimeImmutable($this->g->now))->modify('+'.$e['review_hours'].' hours')->format('Y-m-d H:i:s.u');
        $this->g->db->run("UPDATE galeria_events SET state='REVIEW',closed_at=?,review_until=? WHERE id=?",[$this->g->now,$until,$e['id']]);return ['event_id'=>$e['id'],'state'=>'REVIEW','result_version'=>$r['version'],'review_until'=>$until];
    }
    public function invalidate(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='REVIEW'||$this->g->now>=$e['review_until'])throw new Problem('Invalidação somente durante revisão publicada.',409);
        $v=$this->g->db->row('SELECT * FROM galeria_votes WHERE id=? AND event_id=? FOR UPDATE',[Domain::id($d['vote_id']??null),$e['id']])??throw new Problem('Voto não encontrado.',404);
        if($v['invalidated_at']===null)$this->g->db->run('UPDATE galeria_votes SET invalidated_at=?,invalidated_by=?,reason=? WHERE id=?',[$this->g->now,$actor,$reason,$v['id']]);
        $r=$this->tally($actor,$e,$reason);return ['event_id'=>$e['id'],'result_version'=>$r['version']];
    }
    public function reopen(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='FINALIZED'||$e['funds_state']!=='FUNDED')throw new Problem('Depois do pagamento, registre conciliação; não se altera resultado ou saldo silenciosamente.',409);
        $until=(new \DateTimeImmutable($this->g->now))->modify('+'.$e['review_hours'].' hours')->format('Y-m-d H:i:s.u');if((int)$e['review_hours']===0)throw new Problem('Regulamento sem janela de revisão; crie incidente de conciliação.',409);
        $this->g->db->run("UPDATE galeria_events SET state='REVIEW',review_until=? WHERE id=?",[$until,$e['id']]);$this->g->incident(null,(int)$e['id'],$reason);return ['event_id'=>$e['id'],'state'=>'REVIEW'];
    }
    public function finalize(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']==='FINALIZED')return ['event_id'=>$e['id'],'state'=>'FINALIZED'];
        if($e['state']!=='REVIEW'||$this->g->now<$e['review_until'])throw new Problem('Aguarde o fim da revisão antes do resultado definitivo.',409);$this->ready($e,true);
        $this->g->db->run("UPDATE galeria_events SET state='FINALIZED' WHERE id=?",[$e['id']]);return ['event_id'=>$e['id'],'state'=>'FINALIZED','result_version'=>$e['result_version']];
    }
    public function settle(int $actor,array $d): array {
        $this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']==='SETTLED')return ['event_id'=>$e['id'],'state'=>'SETTLED'];if($e['state']!=='FINALIZED')throw new Problem('Liquidação exige resultado definitivo.',409);$rows=$this->ready($e,true);
        $r=$this->g->db->row('SELECT data FROM galeria_results WHERE event_id=? AND version=?',[$e['id'],$e['result_version']]);$result=GaleriaInput::decode($r['data']);$winners=$result['winners'];usort($winners,fn($a,$b)=>(int)$a<=>(int)$b);$paid='0';$payouts=[];
        if(!$winners)$this->g->finance->refundPrize($e);
        else{
            $base=bcdiv($e['prize_amount'],(string)count($winners),0);$remaining=(int)bcmod($e['prize_amount'],(string)count($winners),0);
            foreach($winners as $id){$c=$this->g->db->row('SELECT a.* FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id WHERE c.id=? AND c.event_id=?',[$id,$e['id']]);$award=bcadd($base,$remaining-->0?'1':'0',0);$nft=bcdiv(bcmul($award,$c['nft_bps'],0),'10000',0);$frame=bcsub($award,$nft,0);
                foreach(['nft'=>[$c['nft_user'],$nft],'frame'=>[$c['frame_user'],$frame]] as $role=>[$u,$value]){$this->g->finance->move($this->g->finance->prizeAccount($e),$this->g->finance->userAccount((int)$u,$e['prize_asset']),$value,'GALERIA_AWARD','event',(int)$e['id'],"award:$id:$role");$paid=bcadd($paid,$value,0);$payouts[]=['candidate_id'=>$id,'user_id'=>$u,'role'=>$role,'amount'=>$value];}
            }
            if($paid!==$e['prize_amount'])throw new \LogicException('Distribuição do prêmio não conserva o total.');
            $this->g->db->run("UPDATE galeria_events SET funds_state='PAID' WHERE id=?",[$e['id']]);
        }
        foreach($rows as $row){$a=(new Coalizoes($this->g))->agreement((int)$row['agreement_id']);$this->g->finance->finish($a,false);}
        $this->g->db->run("UPDATE galeria_events SET state='SETTLED' WHERE id=?",[$e['id']]);return ['event_id'=>$e['id'],'state'=>'SETTLED','paid'=>$paid,'asset'=>$e['prize_asset'],'payouts'=>$payouts];
    }
    public function cancelInternal(array $e,string $reason): void {
        if($e['state']==='CANCELLED')return;if($e['state']==='SETTLED')throw new Problem('Evento liquidado exige conciliação explícita.',409);
        foreach($this->g->db->all('SELECT * FROM galeria_agreements WHERE event_id=?',[$e['id']]) as $a)if($a['funds_state']==='HELD')(new Coalizoes($this->g))->refund($a,$reason,false);
        $this->g->finance->refundPrize($e);$this->g->db->run("UPDATE galeria_events SET state='CANCELLED',resolution_until=NULL WHERE id=?",[$e['id']]);$this->g->incident(null,(int)$e['id'],$reason);
    }
    public function cancel(int $actor,array $d): array {$reason=$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));$this->cancelInternal($e,$reason);return ['event_id'=>$e['id'],'state'=>'CANCELLED'];}
    public function suspend(int $id,string $reason): void {
        $e=$this->event($id);if(!in_array($e['state'],['REGISTRATION','PREPARED','VOTING','REVIEW'],true))return;
        $until=(new \DateTimeImmutable($this->g->now))->modify('+'.$e['resolution_hours'].' hours')->format('Y-m-d H:i:s.u');$this->g->db->run("UPDATE galeria_events SET previous_state=state,state='SUSPENDED',resolution_until=? WHERE id=?",[$until,$id]);$this->g->incident(null,$id,$reason);
    }
    public function resolve(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$e=$this->event(Domain::id($d['event_id']??null));if($e['state']!=='SUSPENDED')throw new Problem('Evento não está suspenso.',409);
        if(($d['resolution']??'')==='refund'){$this->cancelInternal($e,$reason);return ['event_id'=>$e['id'],'state'=>'CANCELLED'];}
        if(($d['resolution']??'')!=='resume'||$this->g->now>=$e['resolution_until']||($e['previous_state']==='REVIEW'?$this->g->now>=$e['review_until']:$this->g->now>=$e['voting_ends']))throw new Problem('Prazo/obra não podem ser prorrogados unilateralmente. Cancele segundo o regulamento.',409);
        $this->ready($e);$state=$e['previous_state'];$this->g->db->run('UPDATE galeria_events SET state=?,previous_state=NULL,resolution_until=NULL WHERE id=?',[$state,$e['id']]);$this->g->db->run('UPDATE galeria_incidents SET resolution=?,resolved_at=? WHERE event_id=? AND agreement_id IS NULL AND resolved_at IS NULL',[$reason,$this->g->now,$e['id']]);return ['event_id'=>$e['id'],'state'=>$state];
    }
}
