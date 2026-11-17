<?php
declare(strict_types=1);
namespace Piscina;

final class PrizeAllocation {
    // A mesma regra publicada nos eventos: piso da NFT, resíduo para a Moldura.
    public static function split(string $amount,mixed $frame,mixed $nft): array {
        Exact::bounded($amount);
        try {$fb=GaleriaInput::integer($frame,0,10000);$nb=GaleriaInput::integer($nft,0,10000);}
        catch(Problem) {throw new Problem('O contrato não contém os dois percentuais válidos para a premiação.',409,'prize_contract_invalid');}
        if($fb+$nb!==10000)throw new Problem('Os percentuais do contrato precisam somar 100%.',409,'prize_contract_invalid');
        $n=bcdiv(bcmul($amount,(string)$nb,0),'10000',0);$f=bcsub($amount,$n,0);
        return ['frame_bps'=>$fb,'nft_bps'=>$nb,'frame_amount'=>$f,'nft_amount'=>$n,'rounding'=>'NFT_FLOOR_FRAME_REMAINDER'];
    }
}
