<?php
declare(strict_types=1);
namespace Piscina;

// Aditivo imutável, com aceite pessoal das partes. Não altera o contrato original.
final class PrizeConsents {
    public function __construct(private Galeria $g) {}
    public function row(int $id): array {
        GaleriaPremios::ready($this->g->db);
        return $this->g->db->row('SELECT * FROM galeria_prize_consents WHERE id=? FOR UPDATE',[$id])??throw new Problem('Aditivo não encontrado.',404);
    }
    private function terms(array $c): array {
        try {$t=GaleriaInput::decode($c['terms']);}catch(\Throwable){throw new Problem('Aditivo inválido. A premiação foi impedida.',409,'prize_consent_invalid');}
        if(!hash_equals($c['terms_hash'],hash('sha256',Domain::canonical($t)))||($t['scope']??'')!=='DIRECT_ADMIN_PRIZES'||($t['rounding']??'')!=='NFT_FLOOR_FRAME_REMAINDER'||($t['prize_assets']??[])!==['MR','BYC'])throw new Problem('O aditivo não autoriza esta modalidade de prêmio.',409,'prize_consent_invalid');
        foreach(['protocol','association_id','association_key','contract_hash','contract_version','frame_id','nft_id','frame_user','nft_user','frame_bps','nft_bps'] as $key)if((string)($t[$key]??'')!==(string)$c[$key])throw new Problem('Os dados do aditivo estão inconsistentes.',409,'prize_consent_invalid');
        PrizeAllocation::split('1',$t['frame_bps']??null,$t['nft_bps']??null);return $t;
    }
    public function validate(array $c,bool $approved=false): array {
        $this->terms($c);$a=(new PrizeAssociations($this->g))->read($c['protocol'],(int)$c['association_id']);
        foreach(['contract_hash','contract_version','frame_id','nft_id','frame_user','nft_user','frame_bps','nft_bps'] as $key)if((string)$a[$key]!== (string)$c[$key])throw new Problem('O contrato mudou. Este aditivo não autoriza a distribuição nas novas condições.',409,'prize_contract_changed');
        if($approved){
            if($c['state']!=='ACTIVE')throw new Problem('Aguarde o aceite das partes no aditivo de prêmios diretos.',409,'prize_consent_pending');
            foreach(array_unique([$c['frame_user'],$c['nft_user']]) as $u)if(!$this->g->db->row('SELECT user_id FROM galeria_prize_acceptances WHERE consent_id=? AND user_id=? AND terms_hash=?',[$c['id'],$u,$c['terms_hash']]))throw new Problem('Faltam aceites válidos no aditivo. A distribuição foi impedida.',409,'prize_consent_pending');
        }
        return $a;
    }
    public function request(int $actor,array $d): array {
        $this->g->admin($actor,$d);GaleriaInput::fields($d,['protocol','association_id','reason','confirm']);GaleriaPremios::ready($this->g->db);$this->g->newBusiness();
        $a=(new PrizeAssociations($this->g))->read((string)($d['protocol']??''),Domain::id($d['association_id']??null));
        $old=$this->g->db->row('SELECT * FROM galeria_prize_consents WHERE active_key=? FOR UPDATE',[$a['association_key']]);
        if($old){$this->validate($old);return ['consent_id'=>$old['id'],'state'=>$old['state'],'message'=>'Este aditivo já está registrado. Confira os aceites nas Pendências.'];}
        $terms=$a;unset($terms['contract']);$terms+=['schema_version'=>1,'scope'=>'DIRECT_ADMIN_PRIZES','prize_assets'=>['MR','BYC'],'rounding'=>'NFT_FLOOR_FRAME_REMAINDER',
            'conditions'=>'Este aditivo autoriza dividir prêmios diretos destinados pelo Administrador a esta associação ativa, nos percentuais do contrato original. O prêmio precisa estar integralmente financiado em mR$ simulado ou BYC. A parcela da NFT é truncada na menor unidade e o resíduo fica com a Moldura. Não há cobrança para aceitar, promessa de prêmio, mudança de propriedade ou divisão de valores de venda. A autorização vale enquanto o contrato, os beneficiários, os ativos e os percentuais permanecerem válidos. Prêmios pagos mantêm seu histórico.'];
        $hash=hash('sha256',Domain::canonical($terms));
        $this->g->db->run('INSERT INTO galeria_prize_consents(protocol,association_id,association_key,link_id,agreement_id,contract_version,contract_hash,frame_id,nft_id,frame_user,nft_user,frame_bps,nft_bps,terms,terms_hash,created_by,operation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$a['protocol'],$a['association_id'],$a['association_key'],$a['protocol']==='simple'?$a['association_id']:null,$a['protocol']==='physical'?$a['association_id']:null,$a['contract_version'],$a['contract_hash'],$a['frame_id'],$a['nft_id'],$a['frame_user'],$a['nft_user'],$a['frame_bps'],$a['nft_bps'],GaleriaInput::json($terms),$hash,$actor,$this->g->op,$this->g->now]);
        $id=$this->g->db->pdo->lastInsertId();foreach(array_unique([$a['frame_user'],$a['nft_user']]) as $u)$this->g->notify((int)$u,'Prêmios: revise nas Pendências o aditivo #'.$id.' da associação '.$a['frame_name'].' + '.$a['nft_title'].'. Aceitar não movimenta sua carteira.');
        return ['consent_id'=>$id,'state'=>'PENDING','message'=>'Aditivo enviado. Aguardando o aceite dos proprietários nas Pendências; nenhum valor foi movimentado.'];
    }
    public function respond(int $actor,array $d,bool $accept): array {
        $this->g->user($actor);GaleriaInput::fields($d,['consent_id','terms_hash','confirm']);GaleriaInput::confirmed($d);$c=$this->row(Domain::id($d['consent_id']??null));
        if(!in_array((string)$actor,[$c['frame_user'],$c['nft_user']],true))throw new Problem('Somente os participantes desta associação podem aceitar o aditivo.',403);
        $this->terms($c);if(!is_string($d['terms_hash']??null)||!hash_equals($c['terms_hash'],$d['terms_hash']))throw new Problem('O aditivo mudou. Confira os termos antes de confirmar.',409,'version_conflict');
        if($c['state']==='ACTIVE'&&$accept)return ['consent_id'=>$c['id'],'state'=>'ACTIVE','message'=>'O aditivo já está confirmado. Nenhum valor foi movimentado.'];
        if($c['state']!=='PENDING')throw new Problem('Aditivo encerrado. Consulte seu histórico.',409);
        if(!$accept){$this->g->db->run("UPDATE galeria_prize_consents SET state='REJECTED',resolved_at=? WHERE id=?",[$this->g->now,$c['id']]);return ['consent_id'=>$c['id'],'state'=>'REJECTED','message'=>'Aditivo recusado. O contrato original e os saldos foram preservados.'];}
        $this->validate($c);$this->g->db->run('INSERT IGNORE INTO galeria_prize_acceptances(consent_id,user_id,terms_hash,operation_id,created_at) VALUES(?,?,?,?,?)',[$c['id'],$actor,$c['terms_hash'],$this->g->op,$this->g->now]);
        $all=true;foreach(array_unique([$c['frame_user'],$c['nft_user']]) as $u)if(!$this->g->db->row('SELECT user_id FROM galeria_prize_acceptances WHERE consent_id=? AND user_id=? AND terms_hash=?',[$c['id'],$u,$c['terms_hash']]))$all=false;
        if($all){$this->g->db->run("UPDATE galeria_prize_consents SET state='ACTIVE',resolved_at=? WHERE id=?",[$this->g->now,$c['id']]);return ['consent_id'=>$c['id'],'state'=>'ACTIVE','message'=>'Aditivo confirmado pelas partes. A associação está autorizada a receber prêmios diretos, sem cobrança pelo aceite.'];}
        return ['consent_id'=>$c['id'],'state'=>'PENDING','message'=>'Seu aceite foi registrado. Aguardando a confirmação do outro proprietário; nenhum valor foi movimentado.'];
    }
    public function read(int $actor,int $id): array {
        $c=$this->row($id);$u=$this->g->domain->user($actor);if($u['role']!=='admin'&&!in_array((string)$actor,[$c['frame_user'],$c['nft_user']],true))throw new Problem('Aditivo privado.',403);
        $terms=$this->terms($c);$c['acceptances']=$this->g->db->all('SELECT user_id,created_at FROM galeria_prize_acceptances WHERE consent_id=? AND terms_hash=? ORDER BY user_id',[$id,$c['terms_hash']]);
        $c['eligible']=true;$c['notice']=null;try{$this->validate($c);}catch(Problem $e){$c['eligible']=false;$c['notice']=$e->getMessage();}
        $c['terms']=$terms;return $c;
    }
}
