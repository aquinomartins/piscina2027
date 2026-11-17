<?php
declare(strict_types=1);
namespace Piscina;

// Destinação direta sobre o mesmo prêmio, conta financiada e livro da Galeria.
final class GaleriaPremios {
    public function __construct(private Galeria $g) {}
    public static function installed(Database $db): bool {return (bool)$db->row("SELECT version FROM schema_migrations WHERE version='006_premios_associacoes'");}
    public static function ready(Database $db): void {if(!self::installed($db))throw new Problem('Instale a migração 006 antes de usar prêmios diretos.',409,'prizes_pending');}
    public function prize(int $id): array {
        self::ready($this->g->db);$e=(new GaleriaEventos($this->g))->event($id,false);
        if($e['mode']!=='DIRECT')throw new Problem('Prêmio de votação não pode ser destinado diretamente. Respeite o regulamento do evento.',409,'prize_mode');
        $rules=GaleriaInput::decode($e['rules']);
        if(($rules['mode']??'')!=='DIRECT'||($rules['funding']??'')!=='EXISTING_ACCOUNT_FULLY_FUNDED'||($rules['destination']??'')!=='ONE_ACTIVE_ASSOCIATION_WITH_BILATERAL_ADDENDUM'||($rules['rounding']??'')!=='NFT_FLOOR_FRAME_REMAINDER')throw new Problem('O regulamento deste prêmio não autoriza a destinação direta.',409,'prize_mode');
        return $e;
    }
    public function save(int $actor,array $d): array {
        $this->g->admin($actor,$d);GaleriaInput::fields($d,['title','asset','amount','reason','confirm']);self::ready($this->g->db);$this->g->newBusiness();
        $title=GaleriaInput::text($d['title']??null,120);$asset=(string)($d['asset']??'');$amount=GaleriaInput::money($d['amount']??null,$asset);
        $rules=['version'=>1,'mode'=>'DIRECT','funding'=>'EXISTING_ACCOUNT_FULLY_FUNDED','destination'=>'ONE_ACTIVE_ASSOCIATION_WITH_BILATERAL_ADDENDUM','rounding'=>'NFT_FLOOR_FRAME_REMAINDER','description'=>'Premiação direta do Administrador. Não participa de votação nem altera resultados dos eventos.'];
        $this->g->db->run("INSERT INTO galeria_events(mode,title,capacity,voting_starts,voting_ends,review_hours,resolution_hours,prize_asset,prize_amount,rules,created_by,created_at) VALUES('DIRECT',?,NULL,NULL,NULL,NULL,NULL,?,?,?,?,?)",[$title,$asset,$amount,GaleriaInput::json($rules),$actor,$this->g->now]);
        return ['prize_id'=>$this->g->db->pdo->lastInsertId(),'state'=>'DRAFT','message'=>'Prêmio criado. Escolha a origem dos recursos para financiá-lo antes de destinar.'];
    }
    public function fund(int $actor,array $d): array {
        GaleriaInput::fields($d,['prize_id','source','reason','confirm']);$e=$this->prize(Domain::id($d['prize_id']??null));
        $r=(new GaleriaEventos($this->g))->fund($actor,['event_id'=>$e['id']]+$d,true);
        return $r+['prize_id'=>$e['id'],'message'=>'Prêmio integralmente financiado. O valor está reservado e aguarda a destinação à associação.'];
    }
    public function cancel(int $actor,array $d): array {
        GaleriaInput::fields($d,['prize_id','reason','confirm']);$e=$this->prize(Domain::id($d['prize_id']??null));
        $r=(new GaleriaEventos($this->g))->cancel($actor,['event_id'=>$e['id']]+$d,true);
        return $r+['prize_id'=>$e['id'],'message'=>'Prêmio cancelado. Se havia financiamento, os recursos foram devolvidos à origem registrada.'];
    }
    public function preview(int $actor,array $d): array {
        $this->g->domain->admin($actor);GaleriaInput::fields($d,['prize_id','consent_id']);$e=$this->prize(Domain::id($d['prize_id']??null));
        if($e['state']!=='DRAFT'||$e['funds_state']!=='FUNDED')throw new Problem('Escolha um prêmio ainda não destinado e integralmente financiado.',409,'prize_unavailable');
        $account=$this->g->db->row('SELECT id,balance FROM accounts WHERE code=?',['T:'.$e['prize_asset'].':galeria_prize:'.$e['id']]);
        if(!$account||$account['balance']!==$e['prize_amount'])throw new Problem('O saldo reservado não corresponde ao prêmio. Confira a conciliação antes de distribuir.',409,'prize_unfunded');
        $source=$this->g->db->row('SELECT asset,purpose FROM accounts WHERE id=?',[$e['source_account']]);
        if(!$source||$source['asset']!==$e['prize_asset']||!in_array($source['purpose'],['wallet','treasury','galeria_revenue'],true))throw new Problem('Origem dos recursos inválida ou ausente. Confira o financiamento do prêmio.',409,'prize_unfunded');
        $c=(new PrizeConsents($this->g))->row(Domain::id($d['consent_id']??null));$a=(new PrizeConsents($this->g))->validate($c,true);
        $split=PrizeAllocation::split($e['prize_amount'],$a['frame_bps'],$a['nft_bps']);
        $summary=['prize_id'=>$e['id'],'title'=>$e['title'],'asset'=>$e['prize_asset'],'amount'=>$e['prize_amount'],'consent_id'=>$c['id'],'consent_terms_hash'=>$c['terms_hash'],'source_account'=>$e['source_account']]+$a+$split;
        // O cliente recebe termos sem caminhos privados. O original completo fica apenas no registro interno.
        $summary['contract']=GaleriaQueries::terms($a['contract']);$summary['preview_hash']=hash('sha256',Domain::canonical($summary));return $summary;
    }
    public function award(int $actor,array $d): array {
        $this->g->admin($actor,$d);GaleriaInput::fields($d,['prize_id','consent_id','preview_hash','reason','confirm']);$e=$this->prize(Domain::id($d['prize_id']??null));
        $prior=$this->g->db->row('SELECT * FROM galeria_prize_awards WHERE event_id=?',[$e['id']]);
        if($prior){if($prior['consent_id']!==(string)($d['consent_id']??''))throw new Problem('Este prêmio já foi destinado a outra associação.',409,'prize_already_awarded');return $this->receipt($prior)+['already_awarded'=>true];}
        $summary=$this->preview($actor,['prize_id'=>$e['id'],'consent_id'=>$d['consent_id']??null]);
        if(!is_string($d['preview_hash']??null)||!hash_equals($summary['preview_hash'],$d['preview_hash']))throw new Problem('As condições mudaram. Revise novamente a divisão antes de confirmar.',409,'version_conflict');
        $a=(new PrizeAssociations($this->g))->read($summary['protocol'],(int)$summary['association_id']);$snapshot=$summary;$snapshot['contract']=$a['contract'];$snapshot['awarded_by']=(string)$actor;$snapshot['awarded_at']=$this->g->now;
        $this->g->db->run('INSERT INTO galeria_prize_awards(event_id,consent_id,actor_id,operation_id,asset,amount,frame_user,nft_user,frame_bps,nft_bps,frame_amount,nft_amount,snapshot,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$e['id'],$summary['consent_id'],$actor,$this->g->op,$e['prize_asset'],$e['prize_amount'],$summary['frame_user'],$summary['nft_user'],$summary['frame_bps'],$summary['nft_bps'],$summary['frame_amount'],$summary['nft_amount'],GaleriaInput::json($snapshot),$this->g->now]);$id=$this->g->db->pdo->lastInsertId();
        $escrow=$this->g->finance->prizeAccount($e);
        foreach(['nft','frame'] as $role){$value=$summary[$role.'_amount'];if($value!=='0')$this->g->finance->move($escrow,$this->g->finance->userAccount((int)$summary[$role.'_user'],$e['prize_asset']),$value,'GALERIA_AWARD','event',(int)$e['id'],'award:direct:'.$role);}
        $this->g->db->run("UPDATE galeria_events SET state='SETTLED',funds_state='PAID',closed_at=? WHERE id=?",[$this->g->now,$e['id']]);
        foreach(array_unique([$summary['frame_user'],$summary['nft_user']]) as $u)$this->g->notify((int)$u,'Premiação direta #'.$e['id'].' confirmada para '.$summary['frame_name'].' + '.$summary['nft_title'].'. Consulte seus créditos e percentuais no Histórico.');
        return $this->receipt($this->g->db->row('SELECT * FROM galeria_prize_awards WHERE id=?',[$id]));
    }
    private function receipt(array $r): array {return ['award_id'=>$r['id'],'prize_id'=>$r['event_id'],'state'=>'SETTLED','asset'=>$r['asset'],'amount'=>$r['amount'],'frame_user'=>$r['frame_user'],'nft_user'=>$r['nft_user'],'frame_amount'=>$r['frame_amount'],'nft_amount'=>$r['nft_amount'],'message'=>'Premiação confirmada. Os créditos foram divididos pelos percentuais do aditivo e registrados nas carteiras.'];}
    public function list(int $actor,array $q): array {
        $this->g->domain->admin($actor);self::ready($this->g->db);$off=max(0,min(100000,(int)($q['offset']??0)));
        $rows=$this->g->db->all("SELECT e.id,e.title,e.state,e.prize_asset,e.prize_amount,e.funds_state,e.created_at,a.id award_id FROM galeria_events e LEFT JOIN galeria_prize_awards a ON a.event_id=e.id WHERE e.mode='DIRECT' ORDER BY e.id DESC LIMIT 25 OFFSET $off");
        $sources=[];foreach(['wallet','treasury','galeria_revenue'] as $source){$code=$source==='wallet'?'U:'.$actor.':':'T:';foreach(['MR','BYC'] as $asset)$sources[$source][$asset]=$this->g->db->row('SELECT balance FROM accounts WHERE code=?',[$code.$asset.':'.$source])['balance']??'0';}
        return ['rows'=>array_slice($rows,0,24),'offset'=>$off,'has_more'=>count($rows)>24,'sources'=>$sources];
    }
    public function read(int $actor,int $id): array {
        $this->g->domain->admin($actor);$e=$this->prize($id);$e['rules']=GaleriaInput::decode($e['rules']);$e['award']=$this->g->db->row('SELECT * FROM galeria_prize_awards WHERE event_id=?',[$id]);
        if($e['award']){$e['award']['snapshot']=GaleriaInput::decode($e['award']['snapshot']);$e['award']['snapshot']['contract']=GaleriaQueries::terms($e['award']['snapshot']['contract']);}return $e;
    }
    public function history(int $actor,array $q=[]): array {
        if(!self::installed($this->g->db))return [];$this->g->domain->user($actor);$off=max(0,min(100000,(int)($q['offset']??0)));
        $rows=$this->g->db->all("SELECT a.id,a.event_id,a.asset,a.amount,a.frame_user,a.nft_user,a.frame_bps,a.nft_bps,a.frame_amount,a.nft_amount,a.created_at,a.snapshot FROM galeria_prize_awards a WHERE frame_user=? OR nft_user=? ORDER BY a.id DESC LIMIT 24 OFFSET $off",[$actor,$actor]);
        foreach($rows as &$r){$s=GaleriaInput::decode($r['snapshot']);$r['frame_name']=$s['frame_name'];$r['nft_title']=$s['nft_title'];$r['title']=$s['title'];$r['contract_version']=$s['contract_version'];$r['consent_id']=$s['consent_id'];unset($r['snapshot']);}unset($r);return $rows;
    }
}
