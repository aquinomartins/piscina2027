<?php
declare(strict_types=1);
namespace Piscina;
final class Ledger {
    public function __construct(private Database $db) {}
    public function account(?int $user, string $asset, string $purpose = 'wallet', ?string $suffix = null): array {
        $code = $user !== null ? "U:$user:$asset:$purpose" : "T:$asset:$purpose" . ($suffix ? ":$suffix" : '');
        $this->db->run('INSERT INTO accounts(code,user_id,asset,purpose) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)', [$code,$user,$asset,$purpose]);
        return $this->db->row('SELECT * FROM accounts WHERE code=? FOR UPDATE', [$code]);
    }
    public function move(array $from, array $to, string $amount, string $kind, string $reference, ?string $op): void {
        Exact::bounded($amount);
        if ($from['asset'] !== $to['asset'] || $from['id'] === $to['id']) throw new \LogicException('Transferência contábil inválida.');
        $ids = [(int)$from['id'], (int)$to['id']]; sort($ids);
        $this->db->all('SELECT id FROM accounts WHERE id IN (?,?) ORDER BY id FOR UPDATE', $ids);
        $from = $this->db->row('SELECT * FROM accounts WHERE id=?', [$from['id']]);
        $to = $this->db->row('SELECT * FROM accounts WHERE id=?', [$to['id']]);
        if ($from['purpose'] !== 'issuance' && bccomp($from['balance'], $amount, 0) < 0) throw new Problem('Saldo disponível insuficiente. Recursos reservados não podem ser gastos.', 409, 'insufficient_balance');
        $next = bcadd($to['balance'], $amount, 0);
        Exact::bounded($next, true);
        $this->db->run('INSERT INTO ledger_transactions(operation_id,kind,reference,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$op,$kind,$reference]);
        $tx = $this->db->pdo->lastInsertId();
        $this->db->run('INSERT INTO ledger_entries(transaction_id,account_id,amount) VALUES(?,?,?),(?,?,?)', [$tx,$from['id'],'-'.$amount,$tx,$to['id'],$amount]);
        $this->db->run('UPDATE accounts SET balance=balance-? WHERE id=?', [$amount,$from['id']]);
        $this->db->run('UPDATE accounts SET balance=balance+? WHERE id=?', [$amount,$to['id']]);
    }
    public function issue(int $user, string $asset, string $amount, string $ref, string $op): void { $this->move($this->account(null,$asset,'issuance'),$this->account($user,$asset),$amount,'ISSUANCE',$ref,$op); }
    public function balances(int $user): array {
        $out=[]; foreach(['MR','BYC','SHARE'] as $asset) $out[$asset]=['available'=>'0','reserved'=>'0'];
        foreach($this->db->all('SELECT asset,purpose,balance FROM accounts WHERE user_id=?',[$user]) as $r) $out[$r['asset']][$r['purpose']==='reserved'?'reserved':'available']=$r['balance'];
        return $out;
    }
    public function reconcile(): array {
        $balances=$this->db->all('SELECT a.id,a.code,a.balance,COALESCE(SUM(e.amount),0) ledger_balance FROM accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id GROUP BY a.id,a.code,a.balance HAVING a.balance<>COALESCE(SUM(e.amount),0)');
        $unbalanced=$this->db->all('SELECT e.transaction_id,a.asset,SUM(e.amount) total FROM ledger_entries e JOIN accounts a ON a.id=e.account_id GROUP BY e.transaction_id,a.asset HAVING SUM(e.amount)<>0');
        $negative=$this->db->all("SELECT code,balance FROM accounts WHERE purpose<>'issuance' AND balance<0");
        $reserves=$this->db->all("SELECT a.code,a.balance,COALESCE(r.total,0) expected FROM accounts a LEFT JOIN (SELECT user_id,asset,SUM(amount) total FROM reservations WHERE state='ACTIVE' AND asset<>'NFT' GROUP BY user_id,asset) r ON r.user_id=a.user_id AND r.asset=a.asset WHERE a.purpose='reserved' AND a.balance<>COALESCE(r.total,0)");
        $custody=$this->db->all("SELECT id,state,owner_id FROM nfts WHERE (state='POOL' AND owner_id IS NOT NULL) OR (state='PRIVATE' AND owner_id IS NULL)");
        $nftReserves=$this->db->all("SELECT n.id FROM nfts n LEFT JOIN reservations r ON r.proposal_id=n.reserved_proposal AND r.asset='NFT' AND r.state='ACTIVE' WHERE n.reserved_proposal IS NOT NULL AND r.id IS NULL");
        $distribution=$this->db->all("SELECT a.code,a.balance,COALESCE(r.pending,0) expected FROM accounts a LEFT JOIN (SELECT period_id,SUM(amount) pending FROM rights WHERE state='PENDING' GROUP BY period_id) r ON r.period_id=SUBSTRING_INDEX(a.code,':',-1) WHERE a.purpose='distribution' AND a.balance<>COALESCE(r.pending,0)");
        return compact('balances','unbalanced','negative','reserves','custody','nftReserves','distribution');
    }
}
