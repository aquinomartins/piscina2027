<?php
declare(strict_types=1);
namespace Piscina;

// Adaptador dos contratos canônicos. Não cria associação nem muda percentuais.
final class PrizeAssociations {
    public function __construct(private Galeria $g) {}
    public function read(string $protocol,int $id,bool $eligible=true): array {
        $table=match($protocol){'simple'=>'galeria_links','physical'=>'galeria_agreements',default=>throw new Problem('Tipo de associação inválido.')};
        $a=$this->g->db->row("SELECT a.*,f.name frame_name,f.code frame_code,f.holder_id,f.status frame_status,f.version frame_version,f.verified_at,n.title nft_title,n.owner_id,n.state nft_state,n.reserved_proposal,".NftImage::SQL_VERSION." image_version,fu.display_name frame_user_name,nu.display_name nft_user_name FROM $table a JOIN galeria_frames f ON f.id=a.frame_id JOIN nfts n ON n.id=a.nft_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user WHERE a.id=?",[$id])??throw new Problem('Associação não encontrada.',404);
        try {$t=GaleriaInput::decode($a['terms']);}catch(\Throwable){throw new Problem('Contrato ilegível. A distribuição foi impedida.',409,'prize_contract_invalid');}
        $split=PrizeAllocation::split('1',$t['frame_bps']??null,$t['nft_bps']??null);
        foreach(['frame_id','nft_id','frame_user','nft_user'] as $key)if((string)($t[$key]??'')!==$a[$key])throw new Problem('Beneficiários ou ativos inconsistentes no contrato.',409,'prize_contract_invalid');
        if((string)$split['nft_bps']!==$a['nft_bps']||($protocol==='simple'&&($t['mode']??'')!=='SIMPLE_ASSOCIATION'))throw new Problem('Percentuais ou modalidade inconsistentes no contrato.',409,'prize_contract_invalid');
        if($eligible){
            $valid=$protocol==='simple'?$a['state']==='ACTIVE':in_array($a['state'],['RESERVED','EXHIBITING'],true)&&$a['ends_at']>$this->g->now;
            if(!$valid)throw new Problem('A associação precisa estar ativa e sem suspensão para receber um prêmio direto.',409,'prize_association_unavailable');
            $this->g->user((int)$a['frame_user']);$this->g->user((int)$a['nft_user']);
            if($a['holder_id']!==$a['frame_user']||$a['owner_id']!==$a['nft_user']||$a['frame_status']!=='ACTIVE'||!$a['verified_at']||$a['nft_state']!=='PRIVATE'||$a['reserved_proposal']!==null||$a['frame_version']!==(string)($t['frame_version']??'')||$a['image_version']!==($t['image']['version']??null))throw new Problem('Propriedade, disponibilidade ou imagem mudaram. Resolva a associação antes de premiar.',409,'prize_association_unavailable');
            if($protocol==='physical'&&!(new Coalizoes($this->g))->validAuthority($a))throw new Problem('A autorização da exposição não está válida.',409,'prize_association_unavailable');
        }
        return ['protocol'=>$protocol,'association_id'=>$a['id'],'association_key'=>$protocol.':'.$a['id'],'contract_version'=>$a['version'],'contract_hash'=>hash('sha256',Domain::canonical($t)),
            'frame_id'=>$a['frame_id'],'frame_name'=>$a['frame_name'],'frame_code'=>$a['frame_code'],'nft_id'=>$a['nft_id'],'nft_title'=>$a['nft_title'],
            'frame_user'=>$a['frame_user'],'nft_user'=>$a['nft_user'],'frame_user_name'=>$a['frame_user_name'],'nft_user_name'=>$a['nft_user_name'],
            'frame_bps'=>$split['frame_bps'],'nft_bps'=>$split['nft_bps'],'contract'=>$t];
    }
    public function search(int $actor,array $q): array {
        $this->g->domain->admin($actor);GaleriaPremios::ready($this->g->db);
        $text=mb_substr(trim((string)($q['q']??'')),0,80);$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$text).'%';$off=max(0,min(100000,(int)($q['offset']??0)));$sources=[];
        foreach(['simple'=>'galeria_links','physical'=>'galeria_agreements'] as $type=>$table){$active=$type==='simple'?"a.state='ACTIVE'":"a.state IN ('RESERVED','EXHIBITING') AND a.ends_at>UTC_TIMESTAMP(6)";$sources[]="SELECT '$type' protocol,a.id association_id,a.frame_id,a.nft_id,a.frame_user,a.nft_user,a.nft_bps,f.name frame_name,n.title nft_title,fu.display_name frame_user_name,nu.display_name nft_user_name,c.id consent_id,c.state consent_state FROM $table a JOIN galeria_frames f ON f.id=a.frame_id JOIN nfts n ON n.id=a.nft_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user LEFT JOIN galeria_prize_consents c ON c.active_key=CONCAT('$type:',a.id) WHERE ($active) AND (f.name LIKE ? OR n.title LIKE ? OR fu.display_name LIKE ? OR nu.display_name LIKE ?)";}
        $rows=$this->g->db->all('SELECT * FROM ('.implode(' UNION ALL ',$sources).") associations ORDER BY protocol,association_id DESC LIMIT 25 OFFSET $off",array_fill(0,8,$like));
        return ['rows'=>array_slice($rows,0,24),'offset'=>$off,'has_more'=>count($rows)>24];
    }
}
