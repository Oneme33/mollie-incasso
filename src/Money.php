<?php

namespace Lukraak\MollieIncasso;

final class Money
{
    public static function cents(string $value, bool $allowZero = false): int
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new Failure('invalid_amount');
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($cents < ($allowZero ? 0 : 1) || $cents > 99999999) {
            throw new Failure('invalid_amount');
        }
        return $cents;
    }
    public static function value(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
    public static function eur(int $cents): string
    {
        return '€ ' . number_format($cents / 100, 2, ',', '.');
    }
    public static function api(int $cents): array
    {
        return ['currency' => 'EUR', 'value' => self::value($cents)];
    }
    public static function csv(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }
}
