<?php
declare(strict_types=1);
namespace Piscina;

final class PaymentTestLedger {
    public function __construct(private Domain $d,private string $op,private string $now){}
    public function credit(int $actor,array $data): array {
        $this->d->admin($actor);(new PaymentTests($this->d))->enabled();
        if(array_keys($data)!==['intent_id']||!preg_match('/^[a-f0-9]{32}$/D',$data['intent_id']??''))throw new Problem('Operação interna de teste inválida.');
        $r=$this->d->db->row('SELECT * FROM payment_test_intents WHERE id=? FOR UPDATE',[$data['intent_id']])??throw new Problem('Teste não encontrado.',404);
        if((string)$actor!==$r['actor_id']||$r['experimental']!=='1'||$r['currency']!=='BRL'||$r['asset']!=='MR')throw new Problem('Crédito experimental inválido.',409);
        if($r['credit_transaction_id']!==null)return ['intent_id'=>$r['id'],'transaction_id'=>$r['credit_transaction_id'],'state'=>'APPROVED','experimental'=>true];
        if($r['state']!=='READY_TO_CREDIT'||$r['provider_status']!=='approved'||!is_string($r['verified_snapshot'])||!hash_equals(hash_hmac('sha256',$r['verified_snapshot'],CredentialKey::get($this->d->config)),(string)$r['verified_hash']))throw new Problem('Não há aprovação de teste verificada pelo servidor. Nenhum crédito foi lançado.',409);
        $s=json_decode($r['verified_snapshot'],true,32,JSON_THROW_ON_ERROR);
        foreach(['intent_id'=>'id','actor_id'=>'actor_id','amount'=>'amount','destination'=>'destination','method'=>'method','provider_id'=>'provider_id','external_reference'=>'external_reference','collector_id'=>'collector_id'] as $field=>$column)if((string)($s[$field]??'')!==$r[$column])throw new Problem('Confirmação do provedor divergente.',409);
        if(($s['status']??'')!=='approved'||($s['live_mode']??null)!==false||($s['experimental']??null)!==true||($s['currency']??'')!=='BRL')throw new Problem('O provedor não aprovou este teste.',409);
        $to=match($r['destination']){'wallet'=>$this->d->ledger->account($actor,'MR'),'treasury','galeria_revenue'=>$this->d->ledger->account(null,'MR',$r['destination']),default=>throw new Problem('Destino inválido.')};
        $ref='payment-test:'.$r['id'];$this->d->ledger->move($this->d->ledger->account(null,'MR','issuance','mercadopago_test'),$to,$r['amount'],'PAYMENT_TEST_CREDIT',$ref,$this->op);
        $tx=$this->d->db->row('SELECT id FROM ledger_transactions WHERE reference=?',[$ref])['id'];
        $this->d->db->run("UPDATE payment_test_intents SET state='APPROVED',credit_operation_id=?,credit_transaction_id=?,credited_at=?,error_code=NULL WHERE id=?",[$this->op,$tx,$this->now,$r['id']]);
        $this->d->db->run("INSERT INTO payment_test_events(intent_id,kind,data,created_at) VALUES(?,'EXPERIMENTAL_CREDIT',?,?)",[$r['id'],json_encode(['transaction_id'=>$tx,'amount'=>$r['amount'],'asset'=>'MR','experimental'=>true],JSON_THROW_ON_ERROR),$this->now]);
        return ['intent_id'=>$r['id'],'transaction_id'=>$tx,'amount'=>$r['amount'],'asset'=>'MR','destination'=>$r['destination'],'state'=>'APPROVED','experimental'=>true,'message'=>'Pagamento de teste aprovado pelo Mercado Pago. Crédito experimental lançado uma única vez. Não representa dinheiro real.'];
    }
}
