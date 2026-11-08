<?php
declare(strict_types=1);
namespace Piscina;

// Associação sem agenda e sem cobrança. Contratos físicos da migração 004 não mudam.
final class MolduraLinks {
    public function __construct(private Galeria $g) {}
    public static function installed(Database $db): bool {
        return (bool)$db->row("SELECT version FROM schema_migrations WHERE version='005_molduras_simples'");
    }
    private function ready(): void {
        if(!self::installed($this->g->db))throw new Problem('Instale a migração 005 para usar as Molduras simplificadas.',409,'molduras_pending');
    }
    public function proposal(int $id): array {
        $this->ready();
        return $this->g->db->row('SELECT p.*,v.terms,v.expires_at FROM galeria_link_proposals p JOIN galeria_link_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.id=? FOR UPDATE',[$id])??throw new Problem('Proposta de associação não encontrada.',404);
    }
    private function party(int $actor,array $p,array $d): void {
        $this->g->user($actor);
        if(!in_array((string)$actor,[$p['frame_user'],$p['nft_user']],true))throw new Problem('Somente as duas partes podem negociar esta associação.',403);
        if((string)($d['version']??'')!==$p['current_version'])throw new Problem('A proposta mudou. Leia a versão atual.',409);
    }
    private function available(array $f,int $nft): void {
        if($f['status']!=='ACTIVE'||!$f['verified_at']||$f['holder_id']===null)throw new Problem('A Moldura precisa ter um titular e estar disponível.',409);
        if($this->g->db->row("SELECT id FROM galeria_agreements WHERE (frame_id=? OR nft_id=?) AND state IN ('RESERVED','EXHIBITING','SUSPENDED') LIMIT 1",[$f['id'],$nft]))throw new Problem('Há um contrato avançado vigente. Resolva-o antes da associação simples.',409);
        if($this->g->db->row("SELECT id FROM galeria_links WHERE active_frame=? OR active_nft=? LIMIT 1",[$f['id'],$nft]))throw new Problem('Moldura ou NFT já associada. Encerre a associação atual antes de aceitar outra.',409);
    }
    private function terms(int $actor,array $d,?array $old=null): array {
        $this->ready();$this->g->newBusiness();$this->g->user($actor);GaleriaInput::confirmed($d);
        if(($d['license_confirm']??false)!==true)throw new Problem('Confirme a autorização da imagem e as regras de encerramento.');
        $f=(new Molduras($this->g))->frame(Domain::id($d['frame_id']??$old['frame_id']??null));
        $nft=Domain::id($d['nft_id']??$old['nft_id']??null);
        $n=$this->g->db->row('SELECT owner_id FROM nfts WHERE id=?',[$nft]);$nu=(int)($n['owner_id']??0);$fu=(int)$f['holder_id'];
        if(!$fu||!$nu||!in_array($actor,[$fu,$nu],true))throw new Problem('Você precisa ser dono da Moldura ou da NFT para propor esta associação.',403);
        if($old&&($old['frame_id']!==$f['id']||$old['nft_id']!==(string)$nft||$old['frame_user']!==(string)$fu||$old['nft_user']!==(string)$nu))throw new Problem('Troca de ativo ou contraparte exige uma nova proposta.',409);
        $frameUser=$this->g->user($fu);$nftUser=$this->g->user($nu);$n=(new Coalizoes($this->g))->nft($nft,$nu);
        $this->available($f,$nft);
        $np=Exact::units($d['nft_percent']??null,2,true);$fp=Exact::units($d['frame_percent']??null,2,true);
        if(bcadd($np,$fp,0)!=='10000'||bccomp($np,'10000',0)>0)throw new Problem('As duas porcentagens precisam somar 100%, com até duas casas decimais.');
        $expires=isset($d['expires_at'])?GaleriaInput::date($d['expires_at']):(new \DateTimeImmutable($this->g->now))->modify('+7 days')->format('Y-m-d H:i:s.000000');
        if($expires<=$this->g->now||$expires>(new \DateTimeImmutable($this->g->now))->modify('+7 days')->format('Y-m-d H:i:s.u'))throw new Problem('A proposta deve vencer em até sete dias.');
        return [
            'mode'=>'SIMPLE_ASSOCIATION','rules_version'=>1,'frame_id'=>$f['id'],'frame_name'=>$f['name'],'authority_id'=>$f['authority_id'],'frame_version'=>$f['version'],
            'nft_id'=>(string)$nft,'nft_title'=>$n['title'],'creator_id'=>$n['creator_id'],
            'frame_user'=>(string)$fu,'frame_user_name'=>$frameUser['display_name'],'nft_user'=>(string)$nu,'nft_user_name'=>$nftUser['display_name'],
            'nft_bps'=>(int)$np,'frame_bps'=>(int)$fp,'expires_at'=>$expires,'image'=>NftImage::snapshot($n),
            'conditions'=>GaleriaInput::text($d['conditions']??'',2000,0),
            'participation_shares'=>'0','payment'=>'NONE','approval_mode'=>$fu===$nu?'SELF_CONFIRMATION':'BILATERAL',
            'distribution_base'=>'Prêmios de eventos expressamente contratados, conforme as regras desses eventos. Esta associação simples não gera receita nem prêmio automático; a divisão não se aplica à venda da NFT ou da Moldura.',
            'duration'=>'Sem agendamento obrigatório. Entre pessoas diferentes, as partes precisam concordar para encerrar a associação. A venda da Moldura aguarda esse encerramento. Mudança de dono, depósito ou alteração da imagem da NFT suspende a associação, sem trocar os beneficiários ou apagar o acordo. Em caso de suspensão, o Admin pode encerrar o vínculo com motivo auditado, sem alterar percentuais ou pagamentos passados.',
            'license'=>'Autorização de exibição desta imagem e preservação do histórico. A associação não transfere a NFT, direitos autorais ou equipamentos físicos. A vinculação entre ativos do mesmo proprietário é confirmada diretamente por ele.'
        ];
    }
    public function preview(int $actor,array $d): array {
        $old=null;if(!empty($d['link_proposal_id'])){$old=$this->proposal(Domain::id($d['link_proposal_id']));$this->party($actor,$old,$d);}
        $t=$this->terms($actor,$d,$old);return ['terms'=>GaleriaQueries::terms($t),'preview_hash'=>hash('sha256',Domain::canonical($t))];
    }
    private function store(int $id,int $v,int $actor,array $t): void {
        $this->g->db->run('INSERT INTO galeria_link_versions(proposal_id,version,author_id,terms,expires_at,created_at) VALUES(?,?,?,?,?,?)',[$id,$v,$actor,GaleriaInput::json($t),$t['expires_at'],$this->g->now]);
        $this->g->db->run('INSERT INTO galeria_link_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$id,$v,$actor,$this->g->now]);
    }
    public function apply(int $actor,string $action,array $d): array {
        $this->ready();
        if($action==='end')return $this->end($actor,$d);
        if($action==='propose'||$action==='counter'){
            $old=null;if($action==='counter'){$old=$this->proposal(Domain::id($d['link_proposal_id']??null));$this->party($actor,$old,$d);if($old['state']!=='PENDING'||$old['expires_at']<=$this->g->now)throw new Problem('Proposta encerrada ou vencida.',409);}
            $t=$this->terms($actor,$d,$old);
            if(!is_string($d['preview_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['preview_hash']))throw new Problem('Revise os percentuais e os participantes antes de enviar.',409);
            if($old){$id=(int)$old['id'];$v=(int)$old['current_version']+1;if($v>100)throw new Problem('Encerre esta negociação e crie outra.',409);$this->g->db->run('UPDATE galeria_link_proposals SET current_version=? WHERE id=?',[$v,$id]);}
            else{
                if((int)$this->g->db->row("SELECT COUNT(*) n FROM galeria_link_proposals WHERE state='PENDING' AND (frame_user=? OR nft_user=?)",[$actor,$actor])['n']>=50)throw new Problem('Encerre propostas pendentes antes de enviar novas.',409);
                $this->g->db->run('INSERT INTO galeria_link_proposals(frame_id,nft_id,frame_user,nft_user,created_at) VALUES(?,?,?,?,?)',[$t['frame_id'],$t['nft_id'],$t['frame_user'],$t['nft_user'],$this->g->now]);$id=(int)$this->g->db->pdo->lastInsertId();$v=1;
            }
            $this->store($id,$v,$actor,$t);$this->g->db->run('UPDATE galeria_settings SET has_commitments=1 WHERE id=1');
            if($t['approval_mode']==='SELF_CONFIRMATION')return $this->activate($this->proposal($id),$t);
            $this->g->notify((int)$t['frame_user']===$actor?(int)$t['nft_user']:(int)$t['frame_user'],"Molduras: proposta de associação #$id. Confira os percentuais da versão $v nas Pendências.");
            return ['link_proposal_id'=>(string)$id,'version'=>(string)$v,'state'=>'PENDING'];
        }
        GaleriaInput::confirmed($d);$p=$this->proposal(Domain::id($d['link_proposal_id']??null));$this->party($actor,$p,$d);
        if($action==='reject'||$action==='cancel'){
            if($p['state']!=='PENDING')throw new Problem('Proposta já encerrada.',409);
            if($action==='cancel'&&!$this->g->db->row('SELECT author_id FROM galeria_link_versions WHERE proposal_id=? AND author_id=? LIMIT 1',[$p['id'],$actor]))throw new Problem('Somente um proponente pode cancelar; o destinatário pode recusar.',403);
            $state=$action==='cancel'?'CANCELLED':'REJECTED';$this->g->db->run('UPDATE galeria_link_proposals SET state=? WHERE id=?',[$state,$p['id']]);return ['link_proposal_id'=>$p['id'],'state'=>$state];
        }
        if($action!=='accept')throw new Problem('Ação de associação inválida.',404);
        $this->g->newBusiness();$t=GaleriaInput::decode($p['terms']);
        if(($d['license_confirm']??false)!==true||!is_string($d['terms_hash']??null)||!hash_equals(hash('sha256',Domain::canonical($t)),$d['terms_hash']))throw new Problem('Leia e confirme a versão atual e suas autorizações.',409);
        if($p['state']==='ACTIVE'){$a=$this->g->db->row('SELECT id,state FROM galeria_links WHERE proposal_id=?',[$p['id']]);return ['link_id'=>$a['id'],'state'=>$a['state']];}
        if($p['state']!=='PENDING'||$p['expires_at']<=$this->g->now)throw new Problem('Proposta encerrada ou vencida.',409);
        $this->g->user((int)$p['frame_user']);$this->g->user((int)$p['nft_user']);
        $f=(new Molduras($this->g))->frame((int)$p['frame_id']);$n=(new Coalizoes($this->g))->nft((int)$p['nft_id'],(int)$p['nft_user']);
        if($f['holder_id']!==$p['frame_user']||$f['version']!==$t['frame_version']||NftImage::version($n)!==$t['image']['version'])throw new Problem('Titular, NFT ou condições da Moldura mudaram. Negocie outra versão.',409);
        $this->available($f,(int)$p['nft_id']);
        $this->g->db->run('INSERT IGNORE INTO galeria_link_acceptances(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$p['id'],$p['current_version'],$actor,$this->g->now]);
        if((int)$this->g->db->row('SELECT COUNT(*) n FROM galeria_link_acceptances WHERE proposal_id=? AND version=?',[$p['id'],$p['current_version']])['n']<2)return ['link_proposal_id'=>$p['id'],'state'=>'PENDING'];
        return $this->activate($p,$t);
    }
    private function activate(array $p,array $t): array {
        $this->g->db->run('INSERT INTO galeria_links(proposal_id,version,frame_id,nft_id,frame_user,nft_user,active_frame,active_nft,nft_bps,terms,operation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$p['id'],$p['current_version'],$p['frame_id'],$p['nft_id'],$p['frame_user'],$p['nft_user'],$p['frame_id'],$p['nft_id'],$t['nft_bps'],$p['terms'],$this->g->op,$this->g->now]);$id=$this->g->db->pdo->lastInsertId();
        $this->g->db->run("UPDATE galeria_link_proposals SET state='ACTIVE' WHERE id=?",[$p['id']]);
        foreach(array_unique([$p['frame_user'],$p['nft_user']]) as $u)$this->g->notify((int)$u,"Molduras: associação #$id ativada com os percentuais aceitos. Nenhuma cota foi cobrada.");
        return ['link_id'=>$id,'link_proposal_id'=>$p['id'],'state'=>'ACTIVE','participation_shares'=>'0'];
    }
    public function close(array $a,?int $actor,string $reason): void {
        if(!in_array($a['state'],['ACTIVE','SUSPENDED'],true))return;
        $this->g->db->run("UPDATE galeria_links SET state='ENDED',active_frame=NULL,active_nft=NULL,ended_at=?,ended_by=?,end_reason=?,end_operation=? WHERE id=?",[$this->g->now,$actor,$reason,$this->g->op,$a['id']]);
        $this->g->db->run("UPDATE galeria_link_proposals SET state='ENDED' WHERE id=?",[$a['proposal_id']]);
        foreach(array_unique([$a['frame_user'],$a['nft_user']]) as $u)$this->g->notify((int)$u,'Molduras: associação #'.$a['id'].' encerrada. '.$reason);
    }
    private function end(int $actor,array $d): array {
        $this->g->user($actor);GaleriaInput::confirmed($d);
        $a=$this->g->db->row('SELECT * FROM galeria_links WHERE id=? FOR UPDATE',[Domain::id($d['link_id']??null)])??throw new Problem('Associação não encontrada.',404);
        if(!in_array((string)$actor,[$a['frame_user'],$a['nft_user']],true))throw new Problem('Associação de terceiros.',403);
        if($a['state']==='ENDED')return ['link_id'=>$a['id'],'state'=>'ENDED'];
        if($a['frame_user']!==$a['nft_user']&&($a['end_requested_by']===null||(int)$a['end_requested_by']===$actor)){
            if($a['end_requested_by']===null){$this->g->db->run('UPDATE galeria_links SET end_requested_by=? WHERE id=?',[$actor,$a['id']]);$this->g->notify((int)($a['frame_user']===(string)$actor?$a['nft_user']:$a['frame_user']),'Molduras: pedido para encerrar a associação #'.$a['id'].'. Revise e confirme nas Pendências.');}
            return ['link_id'=>$a['id'],'state'=>'END_REQUESTED'];
        }
        $this->close($a,$actor,'Encerramento confirmado pelos proprietários.');return ['link_id'=>$a['id'],'state'=>'ENDED'];
    }
    public function resolve(int $actor,array $d): array {
        $reason=$this->g->admin($actor,$d);$this->ready();
        $a=$this->g->db->row('SELECT * FROM galeria_links WHERE id=? FOR UPDATE',[Domain::id($d['link_id']??null)])??throw new Problem('Associação não encontrada.',404);
        if($a['state']!=='SUSPENDED')throw new Problem('Intervenção administrativa disponível somente para associação suspensa.',409);
        $this->close($a,$actor,'Resolução administrativa motivada: '.$reason);return ['link_id'=>$a['id'],'state'=>'ENDED'];
    }
    public function affected(array $nfts,array $users,string $action): void {
        if(!self::installed($this->g->db))return;
        $where=[];$params=[];
        foreach($nfts as $id){$where[]='nft_id=?';$params[]=$id;}
        foreach($users as $id){$where[]='(frame_user=? OR nft_user=?)';$params[]=$id;$params[]=$id;}
        if(!$where)return;
        foreach($this->g->db->all("SELECT * FROM galeria_links WHERE state='ACTIVE' AND (".implode(' OR ',$where).') FOR UPDATE',$params) as $a){
            $valid=true;
            try{$this->g->user((int)$a['frame_user']);$this->g->user((int)$a['nft_user']);$n=(new Coalizoes($this->g))->nft((int)$a['nft_id'],(int)$a['nft_user']);$valid=NftImage::version($n)===GaleriaInput::decode($a['terms'])['image']['version'];}catch(Problem){$valid=false;}
            if(!$valid){
                $this->g->db->run("UPDATE galeria_links SET state='SUSPENDED',end_reason=? WHERE id=?",['Disponibilidade, titularidade ou imagem alterada: '.$action,$a['id']]);
                foreach(array_unique([$a['frame_user'],$a['nft_user']]) as $u)$this->g->notify((int)$u,'Molduras: associação #'.$a['id'].' suspensa após alteração de disponibilidade, dono ou imagem. Os percentuais e o histórico foram preservados.');
            }
        }
    }
    public function read(int $actor,int $id): array {
        $p=$this->proposal($id);$u=$this->g->domain->user($actor);
        if(!in_array((string)$actor,[$p['frame_user'],$p['nft_user']],true)&&$u['role']!=='admin')throw new Problem('Proposta de terceiros.',403);
        unset($p['terms']);$p['versions']=$this->g->db->all('SELECT version,author_id,terms,expires_at,created_at FROM galeria_link_versions WHERE proposal_id=? ORDER BY version DESC LIMIT 100',[$id]);
        foreach($p['versions'] as &$v){$t=GaleriaInput::decode($v['terms']);$v['terms_hash']=hash('sha256',Domain::canonical($t));$v['terms']=GaleriaQueries::terms($t);}unset($v);
        $p['acceptances']=$this->g->db->all('SELECT version,user_id,created_at FROM galeria_link_acceptances WHERE proposal_id=? ORDER BY version,user_id',[$id]);
        $p['link']=$this->g->db->row('SELECT id,state,created_at,ended_at,end_requested_by,ended_by,end_reason FROM galeria_links WHERE proposal_id=?',[$id]);return $p;
    }
}
