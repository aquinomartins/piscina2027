<?php
declare(strict_types=1);
namespace Piscina;

// Texto do recibo calculado dentro da operação confirmada, a partir da mesma fonte.
final class GaleriaCommunication {
    public static function result(Domain $d,int $actor,string $action,array $r): array {
        if(!str_contains($action,'galeria.'))return [];
        $db=$d->db;$next=null;$message=null;
        if($action==='galeria.buy'){
            $f=$db->row('SELECT name FROM galeria_frames WHERE id=?',[$r['frame_id']]);
            $message='Compra concluída. '.$f['name'].' agora está no seu Portfólio.';
        }elseif(in_array($action,['galeria.link.propose','galeria.link.counter','galeria.link.accept','galeria.link.reject','galeria.link.cancel'],true)){
            $id=$r['link_proposal_id']??null;if(!$id&&isset($r['link_id']))$id=$db->row('SELECT proposal_id FROM galeria_links WHERE id=?',[$r['link_id']])['proposal_id']??null;
            if(!$id)return [];
            $p=$db->row('SELECT p.frame_user,p.nft_user,fu.display_name frame_name,nu.display_name nft_name FROM galeria_link_proposals p JOIN users fu ON fu.id=p.frame_user JOIN users nu ON nu.id=p.nft_user WHERE p.id=?',[$id]);
            $other=$p['frame_user']===(string)$actor?$p['nft_user']:$p['frame_user'];$name=$p['frame_user']===(string)$actor?$p['nft_name']:$p['frame_name'];
            if($r['state']==='ACTIVE')$message='Associação confirmada. O acordo e os percentuais já aparecem no Portfólio dos proprietários. Nenhuma cota foi cobrada.';
            elseif($r['state']==='PENDING'){$next=$other;$message=($action==='galeria.link.counter'?'Contraproposta enviada.':'Proposta enviada.').' Aguardando a resposta de '.$name.'.';}
            elseif($r['state']==='SUSPENDED')$message='A associação está suspensa. Confira o acordo e as condições atuais antes de continuar.';
            else $message=($r['state']==='CANCELLED'?'Proposta cancelada.':'Proposta recusada.').' Ela saiu das pendências abertas e permanece no histórico.';
        }elseif(str_starts_with($action,'galeria.trade.')&&isset($r['trade_id'])){
            $p=$db->row('SELECT p.buyer_id,p.seller_id,b.display_name buyer_name,s.display_name seller_name FROM galeria_trade_proposals p JOIN users b ON b.id=p.buyer_id JOIN users s ON s.id=p.seller_id WHERE p.id=?',[$r['trade_id']]);
            if($r['state']==='SETTLED')$message='Negociação concluída. O pagamento e a transferência da Moldura foram confirmados; o Portfólio já foi atualizado.';
            elseif($r['state']==='PENDING'){$next=$p['buyer_id']===(string)$actor?$p['seller_id']:$p['buyer_id'];$name=$p['buyer_id']===(string)$actor?$p['seller_name']:$p['buyer_name'];$message=($action==='galeria.trade.counter'?'Contraproposta enviada.':($action==='galeria.trade.propose'?'Proposta de negociação enviada.':'Seu aceite foi registrado.')).' Aguardando a resposta de '.$name.'.';}
            else $message='Negociação encerrada. Ela saiu das pendências abertas e permanece no histórico.';
        }elseif($action==='galeria.cancel'){
            $p=$db->row('SELECT a.frame_user,a.nft_user,f.display_name frame_name,n.display_name nft_name FROM galeria_agreements a JOIN users f ON f.id=a.frame_user JOIN users n ON n.id=a.nft_user WHERE a.id=?',[$r['agreement_id']]);
            if($r['state']==='CANCELLATION_REQUESTED'){$next=$p['frame_user']===(string)$actor?$p['nft_user']:$p['frame_user'];$name=$p['frame_user']===(string)$actor?$p['nft_name']:$p['frame_name'];$message='Pedido de cancelamento enviado. Aguardando a confirmação de '.$name.'. As cotas continuam reservadas conforme o contrato.';}
            else $message='Exposição cancelada por acordo. A devolução das cotas foi confirmada conforme o contrato original.';
        }elseif($action==='galeria.link.end'){
            $p=$db->row('SELECT a.frame_user,a.nft_user,f.display_name frame_name,n.display_name nft_name FROM galeria_links a JOIN users f ON f.id=a.frame_user JOIN users n ON n.id=a.nft_user WHERE a.id=?',[$r['link_id']]);
            if($r['state']==='END_REQUESTED'){$next=$p['frame_user']===(string)$actor?$p['nft_user']:$p['frame_user'];$name=$p['frame_user']===(string)$actor?$p['nft_name']:$p['frame_name'];$message='Pedido de encerramento enviado. Aguardando a confirmação de '.$name.'.';}
            else $message='Associação encerrada. O histórico e os percentuais anteriores foram preservados.';
        }elseif(in_array($action,['galeria.offer','admin.galeria.offer','admin.galeria.frame.quick'],true)){
            if(isset($r['offer_id'])){
                $o=$db->row('SELECT o.buyer_id,u.display_name FROM galeria_offers o LEFT JOIN users u ON u.id=o.buyer_id WHERE o.id=?',[$r['offer_id']]);
                if($o['buyer_id']!==null){$next=$o['buyer_id'];$message='Oferta enviada a '.$o['display_name'].'. O pagamento só acontece se essa pessoa confirmar a compra.';}
                else $message='Oferta publicada. A Moldura aguarda um comprador; a propriedade ainda não mudou.';
            }else $message='Moldura criada e guardada sem anúncio. Você pode colocá-la à venda depois.';
        }elseif(in_array($action,['galeria.offer.cancel','admin.galeria.offer.cancel'],true))$message='Oferta cancelada. A propriedade e os saldos foram preservados.';
        return $message===null?[]:['message'=>$message,'next_user_id'=>$next];
    }
}
