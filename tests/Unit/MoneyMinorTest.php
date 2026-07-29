<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rupee/paisa boundary.
 *
 * Everything in the wallet ledger is an integer count of paisa, and these two
 * functions are the only place a decimal rupee figure crosses into it. A
 * rounding error here is not a display bug -- it is money the ledger cannot
 * account for, surfacing months later as a reconciliation failure with no trail
 * back to the operation that caused it.
 */
class MoneyMinorTest extends TestCase
{
    /**
     * The decimal-string path is what Eloquent actually hands back for a
     * decimal(10,2) column, so it is the case that matters most.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function decimalStrings(): array
    {
        return [
            'whole rupees' => ['2500.00', 250_000],
            'no decimal point' => ['2500', 250_000],
            'half rupee' => ['100.50', 10_050],
            'single decimal digit' => ['100.5', 10_050],
            'sub-rupee paisa' => ['100.05', 10_005],
            'zero' => ['0.00', 0],
            'one paisa' => ['0.01', 1],
            'large' => ['99999999.99', 9_999_999_999],
            'negative' => ['-250.00', -25_000],
            'surrounding whitespace' => ['  2500.00  ', 250_000],
        ];
    }

    #[Test]
    #[DataProvider('decimalStrings')]
    public function it_converts_decimal_strings_to_paisa_exactly(string $rupees, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($rupees));
    }

    #[Test]
    public function it_converts_integers_without_touching_a_float(): void
    {
        $this->assertSame(250_000, Money::toMinor(2500));
        $this->assertSame(0, Money::toMinor(0));
        $this->assertSame(-25_000, Money::toMinor(-250));
    }

    #[Test]
    public function it_treats_null_as_zero(): void
    {
        $this->assertSame(0, Money::toMinor(null));
    }

    /**
     * Guards against inventing money. `price_amount` is decimal(10,2) so a
     * third decimal place should never exist, but rounding one up would create
     * a paisa out of nothing -- truncation is the conservative direction.
     */
    #[Test]
    public function it_truncates_beyond_two_decimal_places_rather_than_rounding_up(): void
    {
        $this->assertSame(10_099, Money::toMinor('100.999'));
        $this->assertSame(10_000, Money::toMinor('100.009'));
    }

    /**
     * The classic float failure, asserted directly. 0.1 + 0.2 !== 0.3, and a
     * ledger built on that cannot be summed.
     */
    #[Test]
    public function it_survives_values_that_float_arithmetic_gets_wrong(): void
    {
        $this->assertSame(3_331, Money::toMinor('33.31'));
        $this->assertSame(11_070, Money::toMinor('110.70'));
        $this->assertSame(2_035, Money::toMinor('20.35'));

        // The same three values via the float path would drift; summing the
        // integer results must be exact.
        $this->assertSame(16_436, 3_331 + 11_070 + 2_035);
    }

    #[Test]
    public function it_round_trips_through_minor_units(): void
    {
        foreach (['0.00', '0.01', '1.00', '99.99', '2500.00', '123456.78'] as $rupees) {
            $this->assertSame(
                (float) $rupees,
                Money::fromMinor(Money::toMinor($rupees)),
                "Round trip failed for {$rupees}",
            );
        }
    }

    #[Test]
    public function it_formats_paisa_for_display(): void
    {
        $this->assertSame('Rs. 2,500', Money::pkrMinor(250_000));
        $this->assertSame('Rs. 100.50', Money::pkrMinor(10_050));
        $this->assertSame('Rs. 0', Money::pkrMinor(0));
        $this->assertSame('Rs. 0', Money::pkrMinor(null));
        $this->assertSame('2,500', Money::pkrMinor(250_000, withSymbol: false));
    }
}
