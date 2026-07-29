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

    /*
    |--------------------------------------------------------------------------
    | Minor units
    |--------------------------------------------------------------------------
    | Everything in the wallet ledger is an integer count of paisa. Floats are
    | not permitted anywhere in the money path: 0.1 + 0.2 is not 0.3, and a
    | ledger that cannot be summed exactly is not a ledger.
    |
    | Spot pricing keeps its decimal(10,2) column for display continuity, so
    | these two helpers are the boundary between the two representations.
    */

    /**
     * Rupees to paisa: "2500.00" -> 250000.
     *
     * Decimal strings -- which is what Eloquent hands back for a decimal column
     * -- are parsed digit by digit rather than cast to float first, so the
     * conversion is exact. Anything beyond two decimal places is truncated, not
     * rounded; price_amount is decimal(10,2) so a third digit should never
     * exist, and silently rounding one up would invent money.
     */
    public static function toMinor(int|float|string|null $rupees): int
    {
        if ($rupees === null) {
            return 0;
        }

        if (is_int($rupees)) {
            return $rupees * 100;
        }

        if (is_string($rupees) && preg_match('/^\s*(-?)(\d+)(?:\.(\d{0,2})\d*)?\s*$/', $rupees, $m) === 1) {
            $paisa = str_pad($m[3] ?? '', 2, '0');

            return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) $paisa);
        }

        // Float input, or a string in some shape the pattern did not expect.
        // Rounding is correct here because the value has already lost exactness.
        return (int) round((float) $rupees * 100);
    }

    /** Paisa to rupees: 250000 -> 2500.0. For display and legacy decimal columns only. */
    public static function fromMinor(int $paisa): float
    {
        return $paisa / 100;
    }

    /** Format paisa directly: 250000 -> "Rs. 2,500". */
    public static function pkrMinor(?int $paisa, bool $withSymbol = true): string
    {
        return self::pkr(self::fromMinor($paisa ?? 0), $withSymbol);
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
