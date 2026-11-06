<?php
declare(strict_types=1);
namespace Piscina;

// Usa a conexão, o gate e o livro canônico. Nenhum commit ou emissão aqui.
final class GaleriaFinance {
    public function __construct(private Galeria $g){}
    public function move(array $from,array $to,string $amount,string $kind,string $entity,int $id,string $suffix): void {
        if($amount==='0')return;
        $ref="galeria:$entity:$id:$suffix";
        $this->g->domain->ledger->move($from,$to,$amount,$kind,$ref,$this->g->op);
        $tx=$this->g->db->row('SELECT id FROM ledger_transactions WHERE reference=?',[$ref]);
        $this->g->db->run('INSERT INTO galeria_finance(operation_id,transaction_id,entity_type,entity_id,asset,amount,from_account,to_account,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$this->g->op,$tx['id'],$entity,$id,$from['asset'],$amount,$from['id'],$to['id'],$this->g->now]);
    }
    public function userAccount(int $user,string $asset,string $purpose='wallet'): array {
        if($purpose==='wallet'&&$this->g->db->row('SELECT user_id FROM admin_restrictions WHERE user_id=? AND deleted_at IS NOT NULL',[$user]))$purpose='custody';
        return $this->g->domain->ledger->account($user,$asset,$purpose);
    }
    public function hold(array $a): void {
        foreach(['frame_user','nft_user'] as $role){$u=(int)$a[$role];$this->move($this->userAccount($u,'SHARE'),$this->userAccount($u,'SHARE','galeria_hold'),$a['x'],'GALERIA_HOLD','agreement',(int)$a['id'],"hold:$role");}
    }
    public function finish(array $a,bool $refund): void {
        if($a['funds_state']!=='HELD')return;
        foreach(['frame_user','nft_user'] as $role){$u=(int)$a[$role];$to=$refund?$u:(int)$a['recipient_id'];$this->move($this->userAccount($u,'SHARE','galeria_hold'),$this->userAccount($to,'SHARE'),$a['x'],$refund?'GALERIA_REFUND':'GALERIA_CAPTURE','agreement',(int)$a['id'],($refund?'refund:':'capture:').$role);}
        $this->g->db->run('UPDATE galeria_agreements SET funds_state=? WHERE id=?',[$refund?'RETURNED':'CAPTURED',$a['id']]);
    }
    public function prizeAccount(array $e): array {return $this->g->domain->ledger->account(null,$e['prize_asset'],'galeria_prize',$e['id']);}
    public function refundPrize(array $e): void {
        if($e['funds_state']!=='FUNDED')return;
        $source=$this->g->db->row('SELECT * FROM accounts WHERE id=?',[$e['source_account']]);
        if($source['user_id']!==null)$source=$this->userAccount((int)$source['user_id'],$e['prize_asset']);
        $this->move($this->prizeAccount($e),$source,$e['prize_amount'],'GALERIA_PRIZE_REFUND','event',(int)$e['id'],'refund');
        $this->g->db->run("UPDATE galeria_events SET funds_state='RETURNED' WHERE id=?",[$e['id']]);
    }
}
