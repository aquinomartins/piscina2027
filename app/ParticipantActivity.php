<?php
declare(strict_types=1);
namespace Piscina;

// Projeção somente de leitura. IDs/versões apontam para os protocolos existentes.
final class ParticipantActivity {
    public function __construct(private Domain $d) {}
    private function sources(int $actor,bool $history): array {
        // actor vem da sessão/Domain e é inteiro; não é um filtro da requisição.
        $u=(int)$actor;$out=[];
        $nativeOpen="p.state IN ('WAITING','PARTIAL') AND v.expires_at>UTC_TIMESTAMP(6)";
        $out[]="SELECT 'native' kind,p.id,p.current_version version,p.created_at,NOT EXISTS(SELECT 1 FROM approvals x WHERE x.proposal_id=p.id AND x.version=p.current_version AND x.user_id=$u) needs_action,0 stale FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE (p.buyer_id=$u OR p.seller_id=$u) AND ".($history?"NOT ($nativeOpen)":"($nativeOpen)");
        if(!Galeria::installed($this->d->db))return $out;
        $offerOpen="o.state='ACTIVE' AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP(6))";
        $offerWhere=$history?"(o.buyer_id=$u OR o.seller_id=$u OR s.buyer_id=$u) AND NOT ($offerOpen) AND (op.action IS NULL OR op.action<>'galeria.trade.accept')":"(o.buyer_id=$u OR (o.seller_id=$u AND o.buyer_id IS NOT NULL)) AND ($offerOpen)";
        $out[]="SELECT 'offer' kind,o.id,o.version,COALESCE(s.created_at,o.created_at) created_at,(o.buyer_id=$u) needs_action,(NOT(f.holder_id<=>o.seller_id) OR f.status<>'ACTIVE') stale FROM galeria_offers o JOIN galeria_frames f ON f.id=o.frame_id LEFT JOIN galeria_sales s ON s.offer_id=o.id LEFT JOIN operations op ON op.id=s.operation_id WHERE $offerWhere";
        $tradeOpen="p.state='PENDING' AND JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.expires_at'))>UTC_TIMESTAMP(6)";
        $staleTrade="(NOT(f.holder_id<=>p.seller_id) OR f.status<>'ACTIVE' OR CAST(JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.frame_version')) AS UNSIGNED)<>f.version)";
        $out[]="SELECT 'trade' kind,p.id,p.current_version version,v.created_at,($staleTrade OR NOT EXISTS(SELECT 1 FROM galeria_trade_acceptances x WHERE x.proposal_id=p.id AND x.version=p.current_version AND x.user_id=$u)) needs_action,$staleTrade stale FROM galeria_trade_proposals p JOIN galeria_trade_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN galeria_frames f ON f.id=p.frame_id WHERE (p.buyer_id=$u OR p.seller_id=$u) AND ".($history?"NOT ($tradeOpen)":"($tradeOpen)");
        $physicalOpen="p.state='PENDING' AND v.expires_at>UTC_TIMESTAMP(6)";
        $out[]="SELECT 'physical' kind,p.id,p.current_version version,v.created_at,NOT EXISTS(SELECT 1 FROM galeria_acceptances x WHERE x.proposal_id=p.id AND x.version=p.current_version AND x.user_id=$u) needs_action,0 stale FROM galeria_proposals p JOIN galeria_proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE (p.frame_user=$u OR p.nft_user=$u) AND ".($history?"NOT ($physicalOpen)":"($physicalOpen)");
        if(!$history)$out[]="SELECT 'physical_end' kind,a.id,a.version,a.created_at,(a.cancel_requested_by<>$u) needs_action,0 stale FROM galeria_agreements a WHERE (a.frame_user=$u OR a.nft_user=$u) AND a.state IN ('RESERVED','EXHIBITING','SUSPENDED') AND a.funds_state='HELD' AND a.starts_at>UTC_TIMESTAMP(6) AND a.cancel_requested_by IS NOT NULL";
        if(!MolduraLinks::installed($this->d->db))return $out;
        $simpleOpen="p.state='PENDING' AND v.expires_at>UTC_TIMESTAMP(6)";
        $stale="(NOT(f.holder_id<=>p.frame_user) OR NOT(n.owner_id<=>p.nft_user) OR f.status<>'ACTIVE' OR n.state<>'PRIVATE' OR n.reserved_proposal IS NOT NULL OR CAST(JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.frame_version')) AS UNSIGNED)<>f.version OR ".NftImage::SQL_VERSION."<>JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.image.version')) OR EXISTS(SELECT 1 FROM galeria_links a WHERE a.active_frame=p.frame_id OR a.active_nft=p.nft_id))";
        $out[]="SELECT 'association' kind,p.id,p.current_version version,COALESCE(a.ended_at,v.created_at) created_at,($stale OR NOT EXISTS(SELECT 1 FROM galeria_link_acceptances x WHERE x.proposal_id=p.id AND x.version=p.current_version AND x.user_id=$u)) needs_action,$stale stale FROM galeria_link_proposals p JOIN galeria_link_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN galeria_frames f ON f.id=p.frame_id JOIN nfts n ON n.id=p.nft_id LEFT JOIN galeria_links a ON a.proposal_id=p.id WHERE (p.frame_user=$u OR p.nft_user=$u) AND ".($history?"NOT ($simpleOpen)":"($simpleOpen)");
        if(!$history)$out[]="SELECT 'end' kind,a.id,a.version,a.created_at,(a.end_requested_by<>$u) needs_action,0 stale FROM galeria_links a WHERE (a.frame_user=$u OR a.nft_user=$u) AND a.state IN ('ACTIVE','SUSPENDED') AND a.end_requested_by IS NOT NULL";
        return $out;
    }
    public function counts(int $actor): array {
        if(!$this->d->db->pdo->inTransaction()){$this->d->db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return $this->d->db->transaction(fn()=>$this->counts($actor));}
        $this->d->user($actor);$sql=implode(' UNION ALL ',$this->sources($actor,false));
        $r=$this->d->db->row("SELECT COALESCE(SUM(needs_action=1),0) needs_action,COALESCE(SUM(needs_action=0),0) waiting,COUNT(*) total FROM ($sql) activity");
        $money=$this->d->db->row("SELECT (SELECT COUNT(*) FROM rights r JOIN periods p ON p.id=r.period_id WHERE r.user_id=? AND r.state='PENDING' AND p.state='PUBLISHED') rights_count,(SELECT COUNT(*) FROM fees f JOIN periods p ON p.id=f.period_id WHERE f.user_id=? AND f.state='PENDING' AND p.state='PUBLISHED') fees_count",[$actor,$actor]);
        return ['needs_action'=>(int)$r['needs_action']+(int)$money['rights_count']+(int)$money['fees_count'],'waiting'=>(int)$r['waiting'],'negotiations'=>(int)$r['total'],'rights'=>(int)$money['rights_count'],'fees'=>(int)$money['fees_count']];
    }
    public function page(int $actor,array $q,bool $history=false): array {
        if(!$this->d->db->pdo->inTransaction()){$this->d->db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return $this->d->db->transaction(fn()=>$this->page($actor,$q,$history));}
        $this->d->user($actor);$off=max(0,min(100000,(int)($q['offset']??0)));$sql=implode(' UNION ALL ',$this->sources($actor,$history));
        $order=$history?'created_at DESC,kind,id DESC':'needs_action DESC,created_at DESC,kind,id DESC';
        $index=$this->d->db->all("SELECT * FROM ($sql) activity ORDER BY $order LIMIT 25 OFFSET $off");$more=count($index)>24;$index=array_slice($index,0,24);$rows=[];
        $groups=[];foreach($index as $r)$groups[$r['kind']][]=$r['id'];
        foreach($groups as $kind=>$ids){$marks=implode(',',array_fill(0,count($ids),'?'));foreach($this->details($actor,$kind,$marks,$ids) as $r)$rows[$kind.':'.$r['id']]=$r;}
        $now=$this->d->db->now();$items=[];foreach($index as $i){$key=$i['kind'].':'.$i['id'];if(!isset($rows[$key]))continue;$r=$rows[$key];$r['key']=$key;$r['kind']=$i['kind'];$r['needs_action']=$i['needs_action']==='1';$r['stale']=$i['stale']==='1';$r['version']=$i['version'];$r['date']=$i['created_at'];$items[]=$this->present($actor,$r,$history,$now);}
        return ['rows'=>$items,'offset'=>$off,'has_more'=>$more];
    }
    private function details(int $actor,string $kind,string $marks,array $ids): array {
        $db=$this->d->db;$u=(int)$actor;
        if($kind==='native')return $db->all("SELECT p.id,p.state,p.buyer_id,p.seller_id,v.author_id sender_id,v.asset,v.nft_id,v.quantity,v.total amount,'MR' price_asset,v.expires_at,b.display_name buyer_name,s.display_name seller_name,a.display_name sender_name FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN users b ON b.id=p.buyer_id JOIN users s ON s.id=p.seller_id JOIN users a ON a.id=v.author_id WHERE p.id IN ($marks) AND (p.buyer_id=$u OR p.seller_id=$u)",$ids);
        if($kind==='offer')return $db->all("SELECT o.id,o.state,o.frame_id,f.name frame_name,o.buyer_id,o.seller_id,o.asset price_asset,o.amount,o.expires_at,COALESCE(u.display_name,'Galeria') sender_name,COALESCE(o.seller_id,f.created_by) sender_id,b.display_name buyer_name,COALESCE(s.buyer_id,o.buyer_id) purchase_user FROM galeria_offers o JOIN galeria_frames f ON f.id=o.frame_id LEFT JOIN users u ON u.id=o.seller_id LEFT JOIN users b ON b.id=o.buyer_id LEFT JOIN galeria_sales s ON s.offer_id=o.id WHERE o.id IN ($marks) AND (o.buyer_id=$u OR o.seller_id=$u OR s.buyer_id=$u)",$ids);
        if($kind==='trade'){
            $rows=$db->all("SELECT p.id,p.state,p.frame_id,f.name frame_name,p.buyer_id,p.seller_id,b.display_name buyer_name,s.display_name seller_name,v.author_id sender_id,a.display_name sender_name,v.terms FROM galeria_trade_proposals p JOIN galeria_trade_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN galeria_frames f ON f.id=p.frame_id JOIN users b ON b.id=p.buyer_id JOIN users s ON s.id=p.seller_id JOIN users a ON a.id=v.author_id WHERE p.id IN ($marks) AND (p.buyer_id=$u OR p.seller_id=$u)",$ids);
            foreach($rows as &$r){$t=GaleriaInput::decode($r['terms']);$r+=['price_asset'=>$t['asset'],'amount'=>$t['amount'],'expires_at'=>$t['expires_at']];unset($r['terms']);}unset($r);return $rows;
        }
        if($kind==='end')return $db->all("SELECT a.id,a.proposal_id,a.state,a.frame_id,a.nft_id,a.frame_user,a.nft_user,a.nft_bps,f.name frame_name,n.title nft_title,fu.display_name frame_user_name,nu.display_name nft_user_name,a.end_requested_by sender_id,s.display_name sender_name FROM galeria_links a JOIN galeria_frames f ON f.id=a.frame_id JOIN nfts n ON n.id=a.nft_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user JOIN users s ON s.id=a.end_requested_by WHERE a.id IN ($marks) AND (a.frame_user=$u OR a.nft_user=$u)",$ids);
        if($kind==='physical_end')return $db->all("SELECT a.id,a.proposal_id,a.state,a.frame_id,a.nft_id,a.frame_user,a.nft_user,a.nft_bps,a.x shares,f.name frame_name,n.title nft_title,fu.display_name frame_user_name,nu.display_name nft_user_name,a.cancel_requested_by sender_id,s.display_name sender_name FROM galeria_agreements a JOIN galeria_frames f ON f.id=a.frame_id JOIN nfts n ON n.id=a.nft_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user JOIN users s ON s.id=a.cancel_requested_by WHERE a.id IN ($marks) AND (a.frame_user=$u OR a.nft_user=$u)",$ids);
        $prefix=$kind==='association'?'galeria_link_':'galeria_';$versions=$kind==='association'?'galeria_link_versions':'galeria_proposal_versions';
        $state=$kind==='physical'?'COALESCE(a.state,p.state) state':'p.state';$agreement=$kind==='physical'?' LEFT JOIN galeria_agreements a ON a.proposal_id=p.id':'';
        $rows=$db->all("SELECT p.id,$state,p.frame_id,p.nft_id,p.frame_user,p.nft_user,f.name frame_name,n.title nft_title,fu.display_name frame_user_name,nu.display_name nft_user_name,v.author_id sender_id,s.display_name sender_name,v.expires_at,v.terms,EXISTS(SELECT 1 FROM $versions x WHERE x.proposal_id=p.id AND x.author_id=$u) authored FROM {$prefix}proposals p JOIN $versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN galeria_frames f ON f.id=p.frame_id JOIN nfts n ON n.id=p.nft_id JOIN users fu ON fu.id=p.frame_user JOIN users nu ON nu.id=p.nft_user JOIN users s ON s.id=v.author_id $agreement WHERE p.id IN ($marks) AND (p.frame_user=$u OR p.nft_user=$u)",$ids);
        foreach($rows as &$r){$t=GaleriaInput::decode($r['terms']);$r['nft_bps']=$t['nft_bps'];$r['frame_bps']=$t['frame_bps'];if($kind==='physical')$r['shares']=$t['x'];unset($r['terms']);}unset($r);return $rows;
    }
    private function present(int $actor,array $r,bool $history,string $now): array {
        $u=(string)$actor;$r['open']=!$history;
        if($history&&(in_array($r['state'],['PENDING','WAITING','PARTIAL'],true)||($r['kind']==='offer'&&$r['state']==='ACTIVE'))&&isset($r['expires_at'])&&$r['expires_at']!==null&&$r['expires_at']<=$now)$r['state']='EXPIRED';
        $partyA=$r['frame_user']??$r['seller_id']??$r['sender_id'];$partyB=$r['nft_user']??$r['buyer_id']??$u;$other=$partyA===$u?$partyB:$partyA;
        $r['next_user_id']=$history?null:($r['needs_action']?$u:$other);
        $r['next_user_name']=$r['next_user_id']===null?null:($r['next_user_id']===$u?'Você':($r['frame_user_name']??$r['seller_name']??$r['sender_name']));
        if(!$history&&!$r['needs_action'])$r['next_user_name']=($partyA===$u?($r['nft_user_name']??$r['buyer_name']??$r['sender_name']):($r['frame_user_name']??$r['seller_name']??$r['sender_name']));
        $r['responsibility']=$history?'Encerrada':($r['needs_action']?'Sua ação necessária':'Aguardando resposta');
        $r['can_accept']=!$history&&$r['needs_action']&&!$r['stale'];$r['can_counter']=!$history&&!$r['stale']&&in_array($r['kind'],['association','trade','physical','native'],true);
        $r['can_cancel']=!$history&&($r['kind']!=='association'||$r['authored']==='1');
        $r['can_reject']=!$history&&in_array($r['kind'],['association','trade','physical','native'],true);
        if($r['kind']==='offer'){$r['can_cancel']=!$history&&($r['seller_id']??null)===$u;$r['can_counter']=false;}
        if(in_array($r['kind'],['end','physical_end'],true)){$r['can_cancel']=false;$r['can_reject']=false;$r['can_counter']=false;}
        if($r['stale'])$r['notice']='Os proprietários ou a disponibilidade mudaram. A proposta precisa ser revista ou encerrada; ela não pode ser aceita como está.';
        return $r;
    }
}
