<?php
declare(strict_types=1);
namespace Piscina;
final class Exact {
    public const MAX = '9000000000000000';
    public static function units(mixed $value, int $precision, bool $zero = false): string {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,' . max(1,$precision) . '}))?$/D', $value, $m) || ($precision === 0 && isset($m[2]))) throw new Problem('Quantidade inválida ou casas decimais excedidas.');
        $n = ltrim($m[1] . str_pad($m[2] ?? '', $precision, '0'), '0') ?: '0';
        return self::bounded($n, $zero);
    }
    public static function bounded(string $n, bool $zero = false): string {
        if (!ctype_digit($n) || bccomp($n, self::MAX, 0) > 0 || (!$zero && bccomp($n, '0', 0) <= 0)) throw new Problem('Valor fora dos limites permitidos.');
        return $n;
    }
    public static function total(string $quantity, string $price, string $asset): string {
        $divisor = $asset === 'BYC' ? '100000000' : '1';
        $product = bcmul($quantity, $price, 0);
        return self::bounded(bcdiv(bcadd($product, bcdiv($divisor, '2', 0), 0), $divisor, 0), true);
    }
    public static function distribute(string $total, array $shares): array {
        $sum = '0'; foreach ($shares as $q) $sum = bcadd($sum, (string)$q, 0);
        if ($sum === '0') return [];
        $allocated = '0'; $values = []; $remainders = [];
        foreach ($shares as $id => $q) {
            if ((string)$q === '0') continue;
            $product = bcmul($total, (string)$q, 0);
            $values[$id] = bcdiv($product, $sum, 0); $remainders[$id] = bcmod($product, $sum, 0);
            $allocated = bcadd($allocated, $values[$id], 0);
        }
        uksort($remainders, fn($a,$b) => -bccomp($remainders[$a], $remainders[$b], 0) ?: ((int)$a <=> (int)$b));
        $left = (int)bcsub($total, $allocated, 0);
        foreach (array_keys($remainders) as $id) { if ($left-- <= 0) break; $values[$id] = bcadd($values[$id], '1', 0); }
        return $values;
    }
}
