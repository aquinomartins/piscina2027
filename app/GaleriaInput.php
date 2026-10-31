<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaInput {
    public static function text(mixed $v,int $max=255,int $min=1): string {
        if(!is_string($v)||mb_strlen(trim($v))<$min||mb_strlen(trim($v))>$max)throw new Problem("Texto deve ter entre $min e $max caracteres.");
        return trim($v);
    }
    public static function integer(mixed $v,int $min,int $max): int {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$v)||(int)$v<$min||(int)$v>$max)throw new Problem("Número inteiro entre $min e $max necessário.");
        return (int)$v;
    }
    public static function date(mixed $v): string {
        if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d(?:\.\d{1,6})?)?(?:Z|[+-]\d\d:\d\d)$/D',$v))throw new Problem('Informe data/hora com fuso horário.');
        try{$d=new \DateTimeImmutable($v);$e=\DateTimeImmutable::getLastErrors();if($e&&($e['warning_count']||$e['error_count']))throw new \Exception();return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}catch(\Throwable){throw new Problem('Data/hora inválida.');}
    }
    public static function json(array $a): string {return json_encode($a,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public static function decode(string $s): array {return json_decode($s,true,64,JSON_THROW_ON_ERROR);}
    public static function confirmed(array $d): void {if(($d['confirm']??false)!==true)throw new Problem('Revise o resumo integral e confirme as condições.');}
    public static function fields(array $d,array $allowed): void {if(array_diff(array_keys($d),$allowed))throw new Problem('Campos não permitidos nesta operação.');}
    public static function money(mixed $v,string $asset,bool $zero=false): string {
        if(!in_array($asset,['MR','BYC'],true))throw new Problem('Use somente mR$ simulado ou BYC.');
        return Exact::units($v,$asset==='MR'?2:8,$zero);
    }
}
