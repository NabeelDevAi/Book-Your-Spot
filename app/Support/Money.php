<?php

namespace App\Support;

/**
 * PKR formatting.
 *
 * Laravel's Number::currency() delegates to ext-intl, which is not installed
 * on the target server. V1 is single-currency (SRS 2.3) so a small, explicit
 * formatter is both sufficient and one less deployment dependency.
 */
class Money
{
    public const CURRENCY = 'PKR';

    public const SYMBOL = 'Rs.';

    /**
     * Format an amount for display: 2500 -> "Rs. 2,500".
     *
     * Prices in this product are whole rupees in practice, so decimals are
     * dropped unless the amount actually has a fractional part.
     */
    public static function pkr(int|float|string|null $amount, bool $withSymbol = true): string
    {
        $value = (float) ($amount ?? 0);
        $decimals = self::hasFraction($value) ? 2 : 0;
        $formatted = number_format($value, $decimals, '.', ',');

        return $withSymbol ? self::SYMBOL.' '.$formatted : $formatted;
    }

    /**
     * Compact form for dense tables and stat tiles: 1250 -> "Rs. 1.3k".
     */
    public static function compact(int|float|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);

        return match (true) {
            abs($value) >= 10_000_000 => self::SYMBOL.' '.self::trim($value / 1_000_000).'M',
            abs($value) >= 100_000 => self::SYMBOL.' '.self::trim($value / 1_000).'k',
            default => self::pkr($value),
        };
    }

    /**
     * Rate as owners describe it: "Rs. 100 / 10 min", "Rs. 2,500 / hour".
     */
    public static function rate(int|float|string|null $amount, int $unitMinutes): string
    {
        return self::pkr($amount).' / '.self::duration($unitMinutes);
    }

    /**
     * Human duration: 10 -> "10 min", 60 -> "1 hour", 90 -> "1 hr 30 min".
     */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($remainder === 0) {
            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $hours.' hr '.$remainder.' min';
    }

    private static function hasFraction(float $value): bool
    {
        return abs($value - round($value)) > 0.0001;
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ','), '0'), '.');
    }
}
