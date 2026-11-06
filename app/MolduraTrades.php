<?php
declare(strict_types=1);
namespace Piscina;

// Mesmo protocolo da negociação nativa: versões, dois aceites, gate e ledger.
// Moldura é propriedade individual, não uma quarta moeda na carteira.
final class MolduraTrades {
    public function __construct(private Galeria $g){}
    public function proposal(int $id): array {return $this->g->db->row('SELECT * FROM galeria_trade_proposals WHERE id=? FOR UPDATE',[$id])??throw new Problem('Negociação não encontrada.',404);}
    private function party(int $actor,array $p,array $d): void {
        $this->g->user($actor);if(!in_array((string)$actor,[$p['buyer_id'],$p['seller_id']],true))throw new Problem('Negociação de terceiros.',403);
        if((string)($d['version']??'')!==$p['current_version'])throw new Problem('Leia a versão atual antes de responder.',409);
    }
    private function terms(int $actor,array $d,?array $old=null): array {
        $this->g->newBusiness();$this->g->user($actor);GaleriaInput::confirmed($d);$f=(new Molduras($this->g))->frame(Domain::id($d['frame_id']??$old['frame_id']??null));
        $buyer=Domain::id($d['buyer_id']??$old['buyer_id']??null);$seller=(int)$f['holder_id'];
        if(!$seller||$buyer===$seller||!in_array($actor,[$seller,$buyer],true))throw new Problem('Negocie apenas entre o titular atual e outro comprador identificado.',403);
        $su=$this->g->user($seller);$bu=$this->g->user($buyer);if($old&&($old['frame_id']!==$f['id']||$old['seller_id']!==(string)$seller||$old['buyer_id']!==(string)$buyer))throw new Problem('Titular ou partes alterados; crie outra negociação.',409);
        if($f['status']!=='ACTIVE'||!$f['verified_at'])throw new Problem('Moldura não operacional.',409);
        $asset=$d['asset']??'';$amount=GaleriaInput::money($d['price']??null,$asset,true);$expires=GaleriaInput::date($d['expires_at']??null);if($expires<=$this->g->now||$expires>(new \DateTimeImmutable($this->g->now))->modify('+7 days')->format('Y-m-d H:i:s.u'))throw new Problem('Validade de até sete dias no futuro.');
        $facts=(new Molduras($this->g))->transferFacts($f);$m=GaleriaInput::decode($f['metadata']);return ['blocks'=>$facts['blocks'],'locations'=>$facts['locations'],'transfer_signature'=>hash('sha256',Domain::canonical($facts)),'frame_id'=>$f['id'],'frame_name'=>$f['name'],'authority_id'=>$f['authority_id'],'frame_version'=>$f['version'],'buyer_id'=>(string)$buyer,'buyer_name'=>$bu['display_name'],'seller_id'=>(string)$seller,'seller_name'=>$su['display_name'],'asset'=>$asset,'amount'=>$amount,'expires_at'=>$expires,'conditions'=>GaleriaInput::text($d['conditions']??null,2000),'rights'=>$m['rights']];
    }
    public function preview(int $actor,array $d): array {$old=null;if(!empty($d['trade_id'])){$old=$this->proposal(Domain::id($d['trade_id']));$this->party($actor,$old,$d);}$t=$this->terms($actor,$d,$old);return ['terms'=>$t,'preview_hash'=>hash('sha256',Domain::canonical($t))];}
    private function store(int $id,int $v,int $actor,array $t): void {$this->g->db->run('INSERT INTO galeria_trade_versions(proposal_id,version,author_id,terms,created_at) VALUES(?,?,?,?,?)',[$id,$v,$actor,GaleriaInput::json($t),$this->g->now]);$this->g->db->run('INSERT INTO galeria_trade_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$id,$v,$actor,$this->g->now]);}
    public function apply(int $actor,string $action,array $d): array {
        if($action==='propose'||$action==='counter'){
            $p=null;if($action==='counter'){$p=$this->proposal(Domain::id($d['trade_id']??null));$this->party($actor,$p,$d);if($p['state']!=='PENDING')throw new Problem('Negociação encerrada.',409);}
            $t=$this->terms($actor,$d,$p);if(!is_string($d['preview_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['preview_hash']))throw new Problem('Revise o resumo atualizado antes de enviar.',409);
            if($p){$id=(int)$p['id'];$v=(int)$p['current_version']+1;if($v>100)throw new Problem('Limite de versões alcançado.',409);$this->g->db->run('UPDATE galeria_trade_proposals SET current_version=? WHERE id=?',[$v,$id]);}
            else{if((int)$this->g->db->row("SELECT COUNT(*) n FROM galeria_trade_proposals WHERE state='PENDING' AND (buyer_id=? OR seller_id=?)",[$actor,$actor])['n']>=50)throw new Problem('Encerre negociações pendentes antes de iniciar outras.',409);$this->g->db->run('INSERT INTO galeria_trade_proposals(frame_id,seller_id,buyer_id,created_at) VALUES(?,?,?,?)',[$t['frame_id'],$t['seller_id'],$t['buyer_id'],$this->g->now]);$id=(int)$this->g->db->pdo->lastInsertId();$v=1;}
            $this->store($id,$v,$actor,$t);$this->g->notify((int)$t['buyer_id']===$actor?(int)$t['seller_id']:(int)$t['buyer_id'],"Galeria: negociação de Moldura #$id, versão $v, aguardando revisão.");return ['trade_id'=>(string)$id,'version'=>(string)$v,'state'=>'PENDING'];
        }
        GaleriaInput::confirmed($d);$p=$this->proposal(Domain::id($d['trade_id']??null));$this->party($actor,$p,$d);
        if($action==='reject'){if($p['state']!=='PENDING')throw new Problem('Negociação encerrada.',409);$this->g->db->run("UPDATE galeria_trade_proposals SET state='REJECTED' WHERE id=?",[$p['id']]);return ['trade_id'=>$p['id'],'state'=>'REJECTED'];}
        if($action!=='accept')throw new Problem('Ação de negociação desconhecida.',404);
        $this->g->newBusiness();$v=$this->g->db->row('SELECT terms FROM galeria_trade_versions WHERE proposal_id=? AND version=?',[$p['id'],$p['current_version']]);$t=GaleriaInput::decode($v['terms']);
        if(!is_string($d['terms_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['terms_hash']))throw new Problem('Condições alteradas. Revise novamente.',409);
        if($p['state']==='SETTLED')return ['trade_id'=>$p['id'],'state'=>'SETTLED'];if($p['state']!=='PENDING'||$t['expires_at']<=$this->g->now)throw new Problem('Negociação encerrada ou vencida.',409);
        $this->g->user((int)$p['buyer_id']);$this->g->user((int)$p['seller_id']);$f=(new Molduras($this->g))->frame((int)$p['frame_id']);if($f['version']!==$t['frame_version']||$f['holder_id']!==$p['seller_id'])throw new Problem('Titular, equipamento ou direitos alterados.',409);
        if(!hash_equals($t['transfer_signature'],hash('sha256',Domain::canonical((new Molduras($this->g))->transferFacts($f)))))throw new Problem('Local ou autorização alterados. Renegocie os termos atuais antes de transferir.',409);
        $this->g->db->run('INSERT IGNORE INTO galeria_trade_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$p['id'],$p['current_version'],$actor,$this->g->now]);
        if((int)$this->g->db->row('SELECT COUNT(*) n FROM galeria_trade_acceptances WHERE proposal_id=? AND version=?',[$p['id'],$p['current_version']])['n']<2)return ['trade_id'=>$p['id'],'state'=>'PENDING'];
        $this->g->db->run('INSERT INTO galeria_offers(frame_id,seller_id,buyer_id,asset,amount,terms,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$p['frame_id'],$p['seller_id'],$p['buyer_id'],$t['asset'],$t['amount'],$t['conditions'],$t['expires_at'],$this->g->now]);$offer=$this->g->db->pdo->lastInsertId();
        $sale=(new Molduras($this->g))->buy((int)$p['buyer_id'],['offer_id'=>$offer,'version'=>'1','amount'=>$t['amount'],'asset'=>$t['asset'],'seller_id'=>$p['seller_id'],'confirm'=>true]);$this->g->db->run("UPDATE galeria_trade_proposals SET state='SETTLED' WHERE id=?",[$p['id']]);return $sale+['trade_id'=>$p['id'],'state'=>'SETTLED'];
    }
    public function read(int $actor,int $id): array {$p=$this->proposal($id);$this->g->domain->user($actor);if(!in_array((string)$actor,[$p['buyer_id'],$p['seller_id']],true)&&$this->g->domain->user($actor)['role']!=='admin')throw new Problem('Negociação de terceiros.',403);$p['versions']=$this->g->db->all('SELECT * FROM galeria_trade_versions WHERE proposal_id=? ORDER BY version DESC LIMIT 100',[$id]);foreach($p['versions'] as &$v){$v['terms']=GaleriaInput::decode($v['terms']);$v['terms_hash']=hash('sha256',Domain::canonical($v['terms']));}return $p;}
}
