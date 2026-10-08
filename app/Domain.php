<?php
declare(strict_types=1);
namespace Piscina;
final class Domain {
    public Ledger $ledger;
    public Periods $periods;
    private string $op = '';
    private string $effective = '';
    public function __construct(public Database $db, public array $config) { $this->ledger=new Ledger($db); $this->periods=new Periods($db,$this->ledger,$config); }
    public function user(int $id, bool $approved = false): array {
        $u=$this->db->row('SELECT id,email,display_name,role,status,email_verified_at,approved_at FROM users WHERE id=?',[$id]);
        if (!$u) throw new Problem('Participante não encontrado.',404);
        if ($approved && $u['status']!=='approved') throw new Problem('Seu cadastro precisa ser aprovado para operar.',403);
        return $u;
    }
    public function admin(int $id): array { $u=$this->user($id); if($u['role']!=='admin') throw new Problem('Acesso administrativo necessário.',403); return $u; }
    public function policy(): ?array { $r=$this->db->row('SELECT policy FROM decision_versions ORDER BY id DESC LIMIT 1'); return $r?json_decode($r['policy'],true,512,JSON_THROW_ON_ERROR):null; }
    public function active(): void { if(($this->policy()['assets']??'')!=='internal') throw new Problem('Regras aguardando ativação administrativa.',409,'policy_pending'); }
    public function parameters(): array { return $this->db->row('SELECT * FROM parameter_versions ORDER BY id DESC LIMIT 1'); }
    private function audit(int $actor,string $kind,array $data): void { $this->db->run('INSERT INTO audit_events(actor_id,operation_id,kind,data,created_at) VALUES(?,?,?,?,?)',[$actor,$this->op,$kind,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$this->effective]); }
    private function notify(int $id,string $message): void { $this->db->run('INSERT INTO notifications(user_id,message,created_at) VALUES(?,?,?)',[$id,$message,$this->effective]); }
    public function operation(int $actor,string $action,array $data,string $key): array {
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)) throw new Problem('Chave de idempotência inválida.');
        $hashData=$data;if($action==='nft.image')unset($hashData['image_path'],$hashData['thumbnail_path']);$hash=hash('sha256',self::canonical($hashData));
        return $this->db->transaction(function() use($actor,$action,$data,$key,$hash) {
            $gate=$this->db->row('SELECT * FROM economic_gate WHERE id=1 FOR UPDATE');
            $prior=$this->db->row('SELECT * FROM operations WHERE actor_id=? AND action=? AND idempotency_key=?',[$actor,$action,$key]);
            if($prior) { if(!hash_equals($prior['payload_hash'],$hash)) throw new Problem('Essa chave já foi usada com outras condições.',409,'idempotency_conflict'); return json_decode($prior['result'],true,512,JSON_THROW_ON_ERROR); }
            $this->effective=$this->db->now();
            $this->periods->captureDue($this->effective,(int)$gate['sequence_no']);
            $this->db->run('UPDATE economic_gate SET sequence_no=sequence_no+1 WHERE id=1');
            $this->op=bin2hex(random_bytes(16));
            $this->db->run('INSERT INTO operations(id,actor_id,action,idempotency_key,payload_hash,effective_at,sequence_no) VALUES(?,?,?,?,?,?,?)',[$this->op,$actor,$action,$key,$hash,$this->effective,(int)$gate['sequence_no']+1]);
            $this->expireActor($actor);
            $result=match($action) {
                'admin.activate'=>$this->activate($actor,$data), 'admin.parameters'=>$this->setParameters($actor,$data),
                'admin.approve'=>$this->approveUser($actor,$data), 'admin.mint'=>$this->mint($actor,$data),
                'nft.deposit'=>$this->deposit($actor,$data), 'nft.withdraw'=>$this->withdraw($actor,$data),
                'proposal.create'=>$this->createProposal($actor,$data), 'proposal.revise'=>$this->reviseProposal($actor,$data),
                'proposal.approve'=>$this->approveProposal($actor,$data), 'proposal.cancel'=>$this->closeProposal($actor,$data,'CANCELLED'),
                'proposal.refuse'=>$this->closeProposal($actor,$data,'REFUSED'),
                'listing.create'=>$this->createListing($actor,$data), 'listing.cancel'=>$this->cancelListing($actor,$data),
                'right.accept'=>$this->acceptRight($actor,$data), 'fee.pay'=>$this->payFee($actor,$data),
                'nft.image'=>$this->associateImage($actor,$data),
                default=>throw new Problem('Ação desconhecida.',404)
            };
            $result['operation_id']=$this->op;
            $this->audit($actor,$action,['input'=>$data,'result'=>$result]);
            $this->db->run('UPDATE operations SET result=? WHERE id=?',[json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$this->op]);
            return $result;
        });
    }
    public static function canonical(array $data): string { ksort($data); foreach($data as &$v) if(is_array($v)) $v=json_decode(self::canonical($v),true); return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    private function activate(int $actor,array $d): array {
        $this->admin($actor);
        if(($d['confirm']??false)!==true || ($d['rounding']??'')!=='HALF_UP_CENT') throw new Problem('Revise e confirme as decisões e o arredondamento.');
        $policy=require dirname(__DIR__).'/config/policy.php';
        $policy['rounding_confirmed_by']=$actor;
        $this->db->run('INSERT INTO decision_versions(author_id,policy,created_at) VALUES(?,?,?)',[$actor,json_encode($policy,JSON_THROW_ON_ERROR),$this->effective]);
        return ['policy_version'=>$this->db->pdo->lastInsertId(),'state'=>'ACTIVE'];
    }
    private function setParameters(int $actor,array $d): array {
        $this->admin($actor); $p=$this->parameters();
        $reason=trim((string)($d['reason']??'')); if(mb_strlen($reason)<5 || mb_strlen($reason)>255) throw new Problem('Informe o motivo da alteração.');
        $fields=[]; foreach(['initial_credit','withdraw_money','distribution_base','admin_fee'] as $f) $fields[$f]=array_key_exists($f,$d)?Exact::units($d[$f],2,true):$p[$f];
        $interval=isset($d['interval_minutes'])?filter_var($d['interval_minutes'],FILTER_VALIDATE_INT):($p['interval_minutes']??1440);
        if($interval<15 || $interval>525600) throw new Problem('Periodicidade entre 15 minutos e um ano.');
        $first=$p['first_cutoff'];
        if(isset($d['first_cutoff']) && $d['first_cutoff']!=='') {
            $first=$this->date($d['first_cutoff']);
            if($p['first_cutoff']!==null && $first!==$p['first_cutoff']) throw new Problem('Calendário iniciado é imutável nesta versão.');
            if($p['first_cutoff']===null && $first<=$this->effective) throw new Problem('O primeiro corte deve ser futuro.');
        }
        if($p['first_cutoff']!==null && (int)$interval!==(int)$p['interval_minutes']) throw new Problem('Periodicidade do calendário iniciado é imutável; requer migração explícita.');
        $this->db->run('INSERT INTO parameter_versions(author_id,initial_credit,withdraw_money,distribution_base,admin_fee,interval_minutes,first_cutoff,effective_at,reason) VALUES(?,?,?,?,?,?,?,?,?)',[$actor,...array_values($fields),$interval,$first,$this->effective,$reason]);
        return ['parameter_version'=>$this->db->pdo->lastInsertId()];
    }
    private function nft(int $id): array { return $this->db->row('SELECT * FROM nfts WHERE id=? FOR UPDATE',[$id])??throw new Problem('NFT não encontrada.',404); }
    private function nftEvent(array $n,int $actor,string $kind,?int $to): void { $this->db->run('INSERT INTO nft_events(nft_id,actor_id,kind,from_user,to_user,operation_id,effective_at) VALUES(?,?,?,?,?,?,?)',[$n['id'],$actor,$kind,$n['owner_id'],$to,$this->op,$this->effective]); }
    private function newNft(int $owner,int $actor,string $title): string {
        if((int)$this->db->row('SELECT COUNT(*) n FROM nfts')['n']>=($this->config['max_nfts']??5000))throw new Problem('Limite técnico de NFTs atingido.',409);
        $title=trim($title); if($title==='' || mb_strlen($title)>120) throw new Problem('Título entre 1 e 120 caracteres.');
        $this->db->run('INSERT INTO nfts(creator_id,owner_id,title,created_at) VALUES(?,?,?,?)',[$owner,$owner,$title,$this->effective]);
        $id=$this->db->pdo->lastInsertId(); $this->nftEvent(['id'=>$id,'owner_id'=>null],$actor,'ISSUED',$owner); return $id;
    }
    private function approveUser(int $actor,array $d): array {
        $this->admin($actor); $this->active(); $id=self::id($d['user_id']??null); $u=$this->user($id);
        if(!$u['email_verified_at']) throw new Problem('O e-mail precisa ser verificado antes da aprovação.',409);
        if($this->db->row('SELECT user_id FROM initial_grants WHERE user_id=?',[$id])) return ['user_id'=>(string)$id,'already_granted'=>true];
        if((int)$this->db->row("SELECT COUNT(*) n FROM users WHERE status='approved'")['n'] >= ($this->config['max_users']??500)) throw new Problem('Limite operacional de participantes atingido.',409);
        $p=$this->parameters();
        $this->db->run("UPDATE users SET status='approved',approved_at=? WHERE id=?",[$this->effective,$id]);
        $this->db->run('INSERT INTO initial_grants(user_id,parameter_id,operation_id) VALUES(?,?,?)',[$id,$p['id'],$this->op]);
        if($p['initial_credit']!=='0') $this->ledger->issue($id,'MR',$p['initial_credit'],"grant:$id",$this->op);
        $nft=$this->newNft($id,$actor,'Primeira obra de '.$u['display_name']);
        $this->notify($id,'Cadastro aprovado. Crédito simulado e NFT inicial concedidos.');
        return ['user_id'=>(string)$id,'nft_id'=>$nft,'credit'=>$p['initial_credit']];
    }
    private function mint(int $actor,array $d): array { $this->admin($actor);$this->active();$id=self::id($d['user_id']??null);$this->user($id,true);if(mb_strlen(trim((string)($d['reason']??'')))<5) throw new Problem('Informe o motivo da emissão adicional.');return ['nft_id'=>$this->newNft($id,$actor,(string)($d['title']??''))]; }
    private function deposit(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$n=$this->nft(self::id($d['nft_id']??null));
        if($n['state']!=='PRIVATE' || (int)$n['owner_id']!==$actor || $n['reserved_proposal']!==null) throw new Problem('Deposite somente sua NFT privada e livre de reserva.',409);
        $this->db->run("UPDATE nfts SET owner_id=NULL,state='POOL' WHERE id=?",[$n['id']]);
        $this->db->run('INSERT INTO deposits(nft_id,user_id,operation_id,created_at) VALUES(?,?,?,?)',[$n['id'],$actor,$this->op,$this->effective]);
        $this->ledger->issue($actor,'BYC','1000000000',"deposit:{$this->op}:BYC",$this->op);
        $this->ledger->issue($actor,'SHARE','1',"deposit:{$this->op}:SHARE",$this->op);
        $this->nftEvent($n,$actor,'DEPOSIT',null);return ['nft_id'=>$n['id'],'state'=>'POOL','byc'=>'1000000000','shares'=>'1'];
    }
    private function withdraw(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$n=$this->nft(self::id($d['nft_id']??null));$p=$this->parameters();
        if($n['state']!=='POOL') throw new Problem('Outra pessoa já retirou esta NFT.',409,'nft_unavailable');
        if((string)($d['parameter_version']??'')!==$p['id'] || $this->date($d['quote_expires']??'')<=$this->effective || $this->date($d['quote_expires'])>(new \DateTimeImmutable($this->effective))->modify('+10 minutes')->format('Y-m-d H:i:s.u')) throw new Problem('Condições vencidas ou alteradas. Consulte e confirme novamente.',409,'terms_changed');
        $asset=$d['payment']??'';if(!in_array($asset,['MR','BYC'],true)) throw new Problem('Escolha somente mR$ OU BYC.');
        $amount=$asset==='MR'?$p['withdraw_money']:'1100000000';
        if($amount!=='0') $this->ledger->move($this->ledger->account($actor,$asset),$this->ledger->account(null,$asset,'treasury'),$amount,'WITHDRAWAL',"withdraw:{$this->op}",$this->op);
        $this->db->run("UPDATE nfts SET state='PRIVATE',owner_id=? WHERE id=?",[$actor,$n['id']]);$this->nftEvent($n,$actor,'WITHDRAW',$actor);
        return ['nft_id'=>$n['id'],'payment'=>$asset,'amount'=>$amount];
    }
    public function quote(int $actor,int $nft): array { $this->user($actor,true);$p=$this->parameters();$n=$this->db->row('SELECT state FROM nfts WHERE id=?',[$nft]);if(!$n || $n['state']!=='POOL') throw new Problem('NFT indisponível.',409);return ['nft_id'=>(string)$nft,'parameter_version'=>$p['id'],'mr'=>$p['withdraw_money'],'byc'=>'1100000000','quote_expires'=>(new \DateTimeImmutable($this->db->now()))->modify('+5 minutes')->format('Y-m-d\TH:i:s.u\Z')]; }
    private function terms(array $d,int $seller): array {
        $asset=$d['asset']??''; if(!in_array($asset,['BYC','NFT','SHARE'],true)) throw new Problem('Ativo inválido.');
        $quantity=Exact::units($d['quantity']??'', $asset==='BYC'?8:0);
        $price=Exact::units($d['unit_price']??'',2,true);$nft=null;
        if($asset==='NFT') {if($quantity!=='1') throw new Problem('Cada NFT tem quantidade 1.');$nft=self::id($d['nft_id']??null);$n=$this->nft($nft);if($n['state']!=='PRIVATE'||(int)$n['owner_id']!==$seller) throw new Problem('NFT não pertence ao vendedor ou está na piscina.',409);}
        $expires=$this->date($d['expires_at']??'');$limit=(new \DateTimeImmutable($this->effective))->modify('+7 days')->format('Y-m-d H:i:s.u');
        if($expires<=$this->effective || $expires>$limit) throw new Problem('Validade deve ser futura e de até 7 dias.');
        return ['asset'=>$asset,'nft_id'=>$nft,'quantity'=>$quantity,'unit_price'=>$price,'total'=>Exact::total($quantity,$price,$asset),'expires_at'=>$expires];
    }
    private function version(int $proposal,int $version,int $actor,array $t): void { $this->db->run('INSERT INTO proposal_versions(proposal_id,version,author_id,asset,nft_id,quantity,unit_price,total,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$proposal,$version,$actor,$t['asset'],$t['nft_id'],$t['quantity'],$t['unit_price'],$t['total'],$t['expires_at'],$this->effective]); }
    private function createProposal(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$buyer=self::id($d['buyer_id']??null);$seller=self::id($d['seller_id']??null);
        if($buyer===$seller || !in_array($actor,[$buyer,$seller],true)) throw new Problem('Você precisa ser comprador ou vendedor, com outra contraparte.');
        $this->user($buyer,true);$this->user($seller,true);$t=$this->terms($d,$seller);
        $this->db->run('INSERT INTO proposals(buyer_id,seller_id,created_at) VALUES(?,?,?)',[$buyer,$seller,$this->effective]);$id=(int)$this->db->pdo->lastInsertId();$this->version($id,1,$actor,$t);
        if(($d['approve']??false)===true) $this->reserveAndApprove($actor,$this->proposal($id));
        $this->notify($actor===$buyer?$seller:$buyer,'Nova proposta #'.$id.'. Revise as condições antes de aprovar.');
        return ['proposal_id'=>(string)$id,'version'=>'1','state'=>($d['approve']??false)?'PARTIAL':'WAITING'];
    }
    private function proposal(int $id): array { $p=$this->db->row('SELECT * FROM proposals WHERE id=? FOR UPDATE',[$id])??throw new Problem('Proposta não encontrada.',404);$t=$this->db->row('SELECT * FROM proposal_versions WHERE proposal_id=? AND version=?',[$id,$p['current_version']]);return array_merge($p,$t); }
    private function party(int $actor,array $p,array $d): void {if(!in_array($actor,[(int)$p['buyer_id'],(int)$p['seller_id']],true)) throw new Problem('Proposta privada de outros participantes.',403);if((string)($d['version']??'')!==$p['current_version']) throw new Problem('A versão mudou. Revise e confirme os novos termos.',409,'version_conflict');}
    private function open(array $p): bool {
        if($p['state']==='EXPIRED')return false;
        if(!in_array($p['state'],['WAITING','PARTIAL'],true)) throw new Problem('Proposta já finalizada.',409);
        if($p['expires_at']<=$this->effective) {$this->release($p,'EXPIRED');return false;}return true;
    }
    private function release(array $p,string $state): void {
        foreach($this->db->all("SELECT * FROM reservations WHERE proposal_id=? AND version=? AND state='ACTIVE' ORDER BY id",[$p['id'],$p['current_version']]) as $r) {
            if($r['asset']==='NFT') $this->db->run('UPDATE nfts SET reserved_proposal=NULL WHERE id=? AND reserved_proposal=?',[$p['nft_id'],$p['id']]);
            elseif($r['amount']!=='0') $this->ledger->move($this->ledger->account((int)$r['user_id'],$r['asset'],'reserved'),$this->ledger->account((int)$r['user_id'],$r['asset']),$r['amount'],'RESERVE_RELEASE',"release:{$r['id']}",$this->op?:null);
            $this->db->run("UPDATE reservations SET state='RELEASED' WHERE id=?",[$r['id']]);
        }
        $this->db->run('UPDATE proposals SET state=? WHERE id=?',[$state,$p['id']]);
    }
    private function reserveAndApprove(int $actor,array $p): void {
        $this->user($actor,true);
        if($this->db->row('SELECT user_id FROM approvals WHERE proposal_id=? AND version=? AND user_id=?',[$p['id'],$p['current_version'],$actor])) return;
        $buyer=(int)$p['buyer_id']===$actor;$asset=$buyer?'MR':$p['asset'];$amount=$buyer?$p['total']:$p['quantity'];
        if($asset==='NFT') {
            $n=$this->nft((int)$p['nft_id']);if($n['state']!=='PRIVATE'||(int)$n['owner_id']!==$actor||$n['reserved_proposal']!==null) throw new Problem('NFT indisponível ou reservada.',409);
            $this->db->run('UPDATE nfts SET reserved_proposal=? WHERE id=?',[$p['id'],$n['id']]);
        } elseif($amount!=='0') $this->ledger->move($this->ledger->account($actor,$asset),$this->ledger->account($actor,$asset,'reserved'),$amount,'RESERVE',"reserve:{$p['id']}:{$p['current_version']}:$actor",$this->op);
        $this->db->run('INSERT INTO reservations(proposal_id,version,user_id,asset,amount) VALUES(?,?,?,?,?)',[$p['id'],$p['current_version'],$actor,$asset,$amount]);
        $this->db->run('INSERT INTO approvals(proposal_id,version,user_id,created_at) VALUES(?,?,?,?)',[$p['id'],$p['current_version'],$actor,$this->effective]);
        $this->db->run("UPDATE proposals SET state='PARTIAL' WHERE id=?",[$p['id']]);
    }
    private function approveProposal(int $actor,array $d): array {
        $this->active();$p=$this->proposal(self::id($d['proposal_id']??null));$this->party($actor,$p,$d);
        if(!$this->open($p)) return ['proposal_id'=>$p['id'],'state'=>'EXPIRED'];
        $this->user((int)$p['buyer_id'],true);$this->user((int)$p['seller_id'],true);$this->reserveAndApprove($actor,$p);
        $count=(int)$this->db->row('SELECT COUNT(*) n FROM approvals WHERE proposal_id=? AND version=?',[$p['id'],$p['current_version']])['n'];
        if($count===2) {
            $buyer=(int)$p['buyer_id'];$seller=(int)$p['seller_id'];
            if($p['asset']==='NFT') { $n=$this->nft((int)$p['nft_id']);if((int)$n['owner_id']!==$seller || (int)$n['reserved_proposal']!==(int)$p['id'] || $n['state']!=='PRIVATE') throw new Problem('Reserva da NFT inconsistente.',409);$this->db->run('UPDATE nfts SET owner_id=?,reserved_proposal=NULL WHERE id=?',[$buyer,$n['id']]);$this->nftEvent($n,$actor,'SALE',$buyer);}
            else $this->ledger->move($this->ledger->account($seller,$p['asset'],'reserved'),$this->ledger->account($buyer,$p['asset']),$p['quantity'],'SETTLEMENT',"sale:{$p['id']}:asset",$this->op);
            if($p['total']!=='0') $this->ledger->move($this->ledger->account($buyer,'MR','reserved'),$this->ledger->account($seller,'MR'),$p['total'],'SETTLEMENT',"sale:{$p['id']}:payment",$this->op);
            $this->db->run("UPDATE reservations SET state='CONSUMED' WHERE proposal_id=? AND version=? AND state='ACTIVE'",[$p['id'],$p['current_version']]);
            $this->db->run("UPDATE proposals SET state='SETTLED',settled_at=? WHERE id=?",[$this->effective,$p['id']]);
            $this->db->run('INSERT INTO settlements(proposal_id,version,operation_id) VALUES(?,?,?)',[$p['id'],$p['current_version'],$this->op]);
            $this->notify($buyer,'Proposta #'.$p['id'].' concluída.');$this->notify($seller,'Proposta #'.$p['id'].' concluída.');
        }
        return ['proposal_id'=>$p['id'],'version'=>$p['current_version'],'state'=>$count===2?'SETTLED':'PARTIAL'];
    }
    private function reviseProposal(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$p=$this->proposal(self::id($d['proposal_id']??null));$this->party($actor,$p,$d);if(!$this->open($p)) return ['state'=>'EXPIRED'];
        $this->release($p,'WAITING');$t=$this->terms($d,(int)$p['seller_id']);$v=(int)$p['current_version']+1;$this->version((int)$p['id'],$v,$actor,$t);
        $this->db->run("UPDATE proposals SET current_version=?,state='WAITING' WHERE id=?",[$v,$p['id']]);
        if(($d['approve']??false)===true) $this->reserveAndApprove($actor,$this->proposal((int)$p['id']));
        return ['proposal_id'=>$p['id'],'version'=>(string)$v,'state'=>($d['approve']??false)?'PARTIAL':'WAITING'];
    }
    private function closeProposal(int $actor,array $d,string $state): array { $this->user($actor,true);$p=$this->proposal(self::id($d['proposal_id']??null));$this->party($actor,$p,$d);if(!$this->open($p)) return ['state'=>'EXPIRED'];$this->release($p,$state);return ['proposal_id'=>$p['id'],'state'=>$state]; }
    private function createListing(int $actor,array $d): array { $this->active();$this->user($actor,true);$side=$d['side']??'';if(!in_array($side,['BUY','SELL'],true)) throw new Problem('Escolha compra ou venda.');$t=$this->terms($d,$side==='SELL'||($d['asset']??'')!=='NFT'?$actor:self::id($d['owner_id']??null));$this->db->run('INSERT INTO listings(user_id,side,asset,nft_id,quantity,unit_price,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$actor,$side,$t['asset'],$t['nft_id'],$t['quantity'],$t['unit_price'],$t['expires_at'],$this->effective]);return ['listing_id'=>$this->db->pdo->lastInsertId()]; }
    private function cancelListing(int $actor,array $d): array {$this->user($actor,true);$id=self::id($d['listing_id']??null);$r=$this->db->row('SELECT * FROM listings WHERE id=?',[$id]);if(!$r || (int)$r['user_id']!==$actor) throw new Problem('Anúncio não pertence a você.',403);$this->db->run("UPDATE listings SET state='CANCELLED' WHERE id=?",[$id]);return ['listing_id'=>(string)$id,'state'=>'CANCELLED'];}
    private function acceptRight(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$r=$this->db->row("SELECT r.*,p.state period_state FROM rights r JOIN periods p ON p.id=r.period_id WHERE r.id=? FOR UPDATE",[self::id($d['right_id']??null)]);
        if(!$r || (int)$r['user_id']!==$actor) throw new Problem('Recebível não pertence a você.',403);if($r['period_state']!=='PUBLISHED') throw new Problem('Período em processamento.',409);
        if($r['state']==='ACCEPTED') return ['right_id'=>$r['id'],'state'=>'ACCEPTED','amount'=>$r['amount']];
        $this->ledger->move($this->ledger->account(null,'MR','distribution',$r['period_id']),$this->ledger->account($actor,'MR'),$r['amount'],'DISTRIBUTION_ACCEPT',"right:{$r['id']}",$this->op);
        $this->db->run("UPDATE rights SET state='ACCEPTED',accepted_operation=? WHERE id=?",[$this->op,$r['id']]);return ['right_id'=>$r['id'],'state'=>'ACCEPTED','amount'=>$r['amount']];
    }
    private function payFee(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$r=$this->db->row('SELECT f.*,p.state period_state FROM fees f JOIN periods p ON p.id=f.period_id WHERE f.id=? FOR UPDATE',[self::id($d['fee_id']??null)]);
        if(!$r || (int)$r['user_id']!==$actor) throw new Problem('Taxa não pertence a você.',403);if($r['period_state']!=='PUBLISHED') throw new Problem('Período em processamento.',409);
        if($r['state']==='PAID') return ['fee_id'=>$r['id'],'state'=>'PAID'];
        if($r['amount']!=='0') $this->ledger->move($this->ledger->account($actor,'MR'),$this->ledger->account(null,'MR','treasury'),$r['amount'],'FEE_PAYMENT',"fee:{$r['id']}",$this->op);
        $this->db->run("UPDATE fees SET state='PAID',paid_operation=? WHERE id=?",[$this->op,$r['id']]);return ['fee_id'=>$r['id'],'state'=>'PAID','amount'=>$r['amount']];
    }
    private function associateImage(int $actor,array $d): array {
        $this->active();$this->user($actor,true);$n=$this->nft(self::id($d['nft_id']??null));if((int)$n['creator_id']!==$actor || $n['image_path']!==null) throw new Problem('Somente o criador pode concluir um único envio por NFT.',409);
        foreach(['image_path','thumbnail_path'] as $field) if(!preg_match('/^[a-f0-9]{32}(?:-thumb)?\.jpg$/D',$d[$field]??'') || !is_file($this->config['storage'].'/images/'.$d[$field])) throw new Problem('Imagem processada indisponível.',409);
        $this->db->run('UPDATE nfts SET image_path=?,thumbnail_path=?,image_sha=? WHERE id=?',[$d['image_path'],$d['thumbnail_path'],$d['image_sha'],$n['id']]);return ['nft_id'=>$n['id'],'image'=>'READY'];
    }
    private function expireActor(int $actor): void {
        $ids=$this->db->all("SELECT p.id FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.state IN ('WAITING','PARTIAL') AND (p.buyer_id=? OR p.seller_id=?) AND v.expires_at<=? ORDER BY p.id LIMIT 100",[$actor,$actor,$this->effective]);
        foreach($ids as $r)$this->release($this->proposal((int)$r['id']),'EXPIRED');
    }
    public function expire(int $limit=100): int {
        return $this->db->transaction(function() use($limit){$this->db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');$this->effective=$this->db->now();$this->op='';$ids=$this->db->all("SELECT p.id FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.state IN ('WAITING','PARTIAL') AND v.expires_at<=? ORDER BY p.id LIMIT ".max(1,min(100,$limit)),[$this->effective]);foreach($ids as $r) $this->release($this->proposal((int)$r['id']),'EXPIRED');$this->db->run("UPDATE listings SET state='EXPIRED' WHERE state='ACTIVE' AND expires_at<=?",[$this->effective]);return count($ids);});
    }
    public static function id(mixed $value): int {if((!is_string($value)&&!is_int($value))||!preg_match('/^[1-9][0-9]{0,12}$/D',(string)$value)) throw new Problem('Identificador inválido.');return (int)$value;}
    public function date(mixed $value): string {
        if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)?$/D',$value)) throw new Problem('Data ISO inválida.');
        try {$d=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));if(\DateTimeImmutable::getLastErrors()!==false) throw new \Exception();return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}catch(\Throwable){throw new Problem('Data inválida.');}
    }
}
