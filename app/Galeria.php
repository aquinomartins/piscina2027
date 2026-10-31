<?php
declare(strict_types=1);
namespace Piscina;

final class Galeria {
    public Database $db;
    public GaleriaFinance $finance;
    public function __construct(public Domain $domain,public string $op='',public string $now='') {
        $this->db=$domain->db;$this->now=$now?:$this->db->now();$this->finance=new GaleriaFinance($this);
    }
    public static function installed(Database $db): bool {return (bool)$db->row("SELECT version FROM schema_migrations WHERE version='004_galeria'");}
    public function enabled(): bool {return ($this->domain->config['galeria']['enabled']??false)===true;}
    public function newBusiness(): void {
        if(!$this->enabled())throw new Problem('Galeria desabilitada na configuração privada.',409,'galeria_disabled');
        $this->domain->active();
        if($this->db->row('SELECT accepting FROM galeria_settings WHERE id=1')['accepting']!=='1')throw new Problem('Novas contratações da Galeria estão suspensas. Consultas e compromissos existentes continuam disponíveis.',409,'galeria_paused');
    }
    public function user(int $u): array {return $this->domain->user($u,true);}
    public function reason(array $d): string {return GaleriaInput::text($d['reason']??null,255,5);}
    public function admin(int $a,array $d): string {$this->domain->admin($a);GaleriaInput::confirmed($d);return $this->reason($d);}
    public function notify(int $u,string $s): void {$this->db->run('INSERT INTO notifications(user_id,message,created_at) VALUES(?,?,?)',[$u,$s,$this->now]);}
    public function incident(?int $agreement,?int $event,string $reason): void {$this->db->run('INSERT INTO galeria_incidents(agreement_id,event_id,reason,operation_id,created_at) VALUES(?,?,?,?,?)',[$agreement,$event,$reason,$this->op,$this->now]);}
    public static function auditInput(array $d,string $action=''): array {unset($d['credential_ref']);if($action==='galeria.vote')unset($d['candidate_id']);return $d;}
    public function apply(int $actor,string $action,array $d): array {
        if(!$this->db->pdo->inTransaction()||$this->op==='')throw new \LogicException('A Galeria exige o gate e a operação canônicos.');
        if(!self::installed($this->db))throw new Problem('Instale a migração 004 antes de usar a Galeria.',503);
        $m=new Molduras($this);$c=new Coalizoes($this);$e=new GaleriaEventos($this);
        return match($action) {
            'admin.galeria.settings'=>$this->settings($actor,$d),
            'admin.galeria.incident'=>$this->recordIncident($actor,$d),
            'admin.galeria.space.save'=>$m->space($actor,$d), 'admin.galeria.point.save'=>$m->point($actor,$d),
            'admin.galeria.frame.save'=>$m->save($actor,$d), 'admin.galeria.frame.allocate'=>$m->allocate($actor,$d),
            'admin.galeria.frame.verify'=>$m->verify($actor,$d), 'admin.galeria.frame.archive'=>$m->archive($actor,$d),
            'admin.galeria.frame.delete'=>$m->deleteDraft($actor,$d), 'admin.galeria.block'=>$m->block($actor,$d),
            'admin.galeria.frame.photo'=>$m->photo($actor,$d), 'admin.galeria.policy'=>$m->policy($actor,$d),
            'admin.galeria.offer','galeria.offer'=>$m->offer($actor,$d), 'galeria.offer.cancel','admin.galeria.offer.cancel'=>$m->cancelOffer($actor,$d),
            'galeria.buy'=>$m->buy($actor,$d),
            'galeria.trade.propose','galeria.trade.counter','galeria.trade.accept','galeria.trade.reject'=>(new MolduraTrades($this))->apply($actor,substr($action,14),$d),
            'galeria.propose'=>$c->propose($actor,$d), 'galeria.counter'=>$c->counter($actor,$d),
            'galeria.accept'=>$c->accept($actor,$d), 'galeria.reject'=>$c->reject($actor,$d),
            'galeria.cancel'=>$c->cancel($actor,$d),
            'admin.galeria.exposure.confirm'=>$c->physical($actor,$d), 'admin.galeria.exposure.complete'=>$c->complete($actor,$d),
            'admin.galeria.exposure.suspend'=>$c->suspendCommand($actor,$d), 'admin.galeria.exposure.resolve'=>$c->resolve($actor,$d),
            'admin.galeria.event.save'=>$e->save($actor,$d), 'admin.galeria.event.publish'=>$e->publish($actor,$d),
            'admin.galeria.event.fund'=>$e->fund($actor,$d), 'admin.galeria.event.prepare'=>$e->prepare($actor,$d),
            'admin.galeria.event.open'=>$e->open($actor,$d), 'admin.galeria.event.credential'=>$e->credential($actor,$d),
            'galeria.vote'=>$e->vote($actor,$d), 'admin.galeria.event.close'=>$e->close($actor,$d),
            'admin.galeria.vote.invalidate'=>$e->invalidate($actor,$d), 'admin.galeria.event.reopen'=>$e->reopen($actor,$d),
            'admin.galeria.event.finalize'=>$e->finalize($actor,$d), 'admin.galeria.event.settle'=>$e->settle($actor,$d),
            'admin.galeria.event.cancel'=>$e->cancel($actor,$d), 'admin.galeria.event.resolve'=>$e->resolve($actor,$d),
            'admin.galeria.tick'=>$this->tick($actor,$d),
            default=>throw new Problem('Ação da Galeria desconhecida.',404)
        };
    }
    private function settings(int $a,array $d): array {
        $reason=$this->admin($a,$d);if(!is_bool($d['accepting']??null))throw new Problem('Informe a suspensão de novas contratações.');
        if($d['accepting']&&!$this->enabled())throw new Problem('Ative galeria.enabled na configuração privada após instalar a migração 004.',409);
        $this->db->run('UPDATE galeria_settings SET accepting=?,updated_at=?,updated_by=?,reason=? WHERE id=1',[(int)$d['accepting'],$this->now,$a,$reason]);
        return ['accepting'=>$d['accepting'],'enabled'=>$this->enabled()];
    }
    private function recordIncident(int $actor,array $d): array {
        $reason=$this->admin($actor,$d);$event=empty($d['event_id'])?null:Domain::id($d['event_id']);$agreement=empty($d['agreement_id'])?null:Domain::id($d['agreement_id']);
        if(!$event&&!$agreement)throw new Problem('Vincule o incidente a um evento ou exposição.');
        if($event)(new GaleriaEventos($this))->event($event);if($agreement){$a=(new Coalizoes($this))->agreement($agreement);if($event&&$a['event_id']!==(string)$event)throw new Problem('Evento e exposição não correspondem.');}
        $this->incident($agreement,$event,$reason);return ['incident_id'=>$this->db->pdo->lastInsertId(),'state'=>'RECORDED','financial_effect'=>'NONE'];
    }
    // Chamado depois da mutação antiga, sob o mesmo gate. Não impede depósito/venda.
    public function reconcileAffected(string $action,array $d): void {
        if(!in_array($action,['nft.deposit','nft.withdraw','nft.image','admin.nft.image_replace','proposal.approve','admin.danger.ban','admin.danger.delete'],true))return;
        if(!self::installed($this->db))return;
        $ids=[];$users=[];
        if(in_array($action,['nft.deposit','nft.withdraw','nft.image','admin.nft.image_replace'],true))$ids[]=(int)($d['nft_id']??0);
        if($action==='proposal.approve'){$p=$this->db->row('SELECT v.nft_id FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.id=?',[$d['proposal_id']??0]);if($p&&$p['nft_id'])$ids[]=(int)$p['nft_id'];}
        if(in_array($action,['admin.danger.ban','admin.danger.delete'],true))$users[]=(int)($d['user_id']??0);
        if(!$ids&&!$users)return;
        $clauses=[];$args=[];foreach($ids as $id){$clauses[]='nft_id=?';$args[]=$id;}foreach($users as $id){$clauses[]='(frame_user=? OR nft_user=?)';$args[]=$id;$args[]=$id;}
        $rows=$this->db->all("SELECT * FROM galeria_agreements WHERE state IN ('RESERVED','EXHIBITING') AND ends_at>? AND (".implode(' OR ',$clauses).') ORDER BY id',[$this->now,...$args]);
        $c=new Coalizoes($this);
        foreach($rows as $r)if(!$c->validAuthority($r))$c->suspend($r,'Autoridade ou mídia alterada por operação canônica: '.$action);
        if($action==='admin.danger.delete')foreach($this->db->all('SELECT id FROM galeria_frames WHERE holder_id=? FOR UPDATE',[$users[0]]) as $f){
            $this->db->run('INSERT INTO galeria_frame_custody(frame_id,source_user_id,operation_id,created_at) VALUES(?,?,?,?)',[$f['id'],$users[0],$this->op,$this->now]);
            $this->db->run("UPDATE galeria_frames SET holder_id=NULL,status='SUSPENDED' WHERE id=?",[$f['id']]);
            $this->db->run('UPDATE galeria_ownership SET ends_at=? WHERE frame_id=? AND ends_at IS NULL',[$this->now,$f['id']]);
            $this->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE'",[$f['id']]);
            (new GaleriaAvailability($this))->frame((int)$f['id']);
            $this->incident(null,null,'Moldura #'.$f['id'].' destinada à custódia para avaliação após encerramento do titular.');
        }
    }
    private function tick(int $actor,array $d): array {
        $this->domain->admin($actor);$changed=0;
        $this->db->run("UPDATE galeria_proposals p JOIN galeria_proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version SET p.state='EXPIRED' WHERE p.state='PENDING' AND v.expires_at<=?",[$this->now]);
        $this->db->run("UPDATE galeria_trade_proposals p JOIN galeria_trade_versions v ON v.proposal_id=p.id AND v.version=p.current_version SET p.state='EXPIRED' WHERE p.state='PENDING' AND JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.expires_at'))<=?",[$this->now]);
        $this->db->run("UPDATE galeria_offers SET state='EXPIRED' WHERE state='ACTIVE' AND expires_at<=?",[$this->now]);
        $c=new Coalizoes($this);$events=new GaleriaEventos($this);
        $cursor=$this->db->row('SELECT agreement_cursor FROM galeria_settings WHERE id=1')['agreement_cursor'];
        $batch=$this->db->all("SELECT * FROM galeria_agreements WHERE state IN ('RESERVED','EXHIBITING','SUSPENDED') AND id>? ORDER BY id LIMIT 100",[$cursor]);
        if(!$batch)$batch=$this->db->all("SELECT * FROM galeria_agreements WHERE state IN ('RESERVED','EXHIBITING','SUSPENDED') ORDER BY id LIMIT 100");
        foreach($batch as $a){
            if($a['state']!=='SUSPENDED'&&$a['ends_at']>$this->now&&!$c->validAuthority($a)){$c->suspend($a,'Reconciliação de autoridade pela tarefa');$changed++;}
            elseif($a['state']==='SUSPENDED'&&$a['event_id']===null&&$a['resolution_until']<=$this->now){$c->refund($a,'Prazo de resolução encerrado');$changed++;}
            elseif($a['state']==='RESERVED'&&$a['starts_at']<=$this->now){$this->db->run("UPDATE galeria_agreements SET state='EXHIBITING' WHERE id=?",[$a['id']]);}
        }
        $this->db->run('UPDATE galeria_settings SET agreement_cursor=? WHERE id=1',[$batch?$batch[array_key_last($batch)]['id']:0]);
        foreach($this->db->all("SELECT * FROM galeria_events WHERE (state IN ('REGISTRATION','PREPARED','VOTING') AND voting_ends<=?) OR (state='REGISTRATION' AND voting_starts<=?) OR (state='SUSPENDED' AND resolution_until<=?) ORDER BY id LIMIT 25",[$this->now,$this->now,$this->now]) as $e){
            if($e['state']==='VOTING'&&$e['voting_ends']<=$this->now){$events->close($actor,['event_id'=>$e['id'],'confirm'=>true,'reason'=>'Encerramento automático no prazo']);$changed++;}
            elseif(($e['state']==='REGISTRATION'&&$e['voting_starts']<=$this->now)||($e['state']==='PREPARED'&&$e['voting_ends']<=$this->now)||($e['state']==='SUSPENDED'&&$e['resolution_until']<=$this->now)){$events->cancel($actor,['event_id'=>$e['id'],'confirm'=>true,'reason'=>'Prazo encerrado sem condições de continuidade']);$changed++;}
        }
        return ['changed'=>$changed];
    }
    public static function cron(Domain $d): array {
        if(!self::installed($d->db))return ['state'=>'NOT_INSTALLED'];
        if($d->db->row('SELECT has_commitments FROM galeria_settings WHERE id=1')['has_commitments']==='0'&&!$d->db->row('SELECT id FROM galeria_proposals LIMIT 1')&&!$d->db->row('SELECT id FROM galeria_events LIMIT 1')&&!$d->db->row('SELECT id FROM galeria_trade_proposals LIMIT 1'))return ['state'=>'IDLE'];
        $actor=$d->db->row("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");if(!$actor)return ['state'=>'NO_ADMIN'];
        try{return $d->operation((int)$actor['id'],'admin.galeria.tick',[],bin2hex(random_bytes(16)));}catch(Problem $e){if($e->error==='calendar_backlog')return ['state'=>'DEFERRED_CALENDAR_RECOVERY'];throw $e;}
    }
}
