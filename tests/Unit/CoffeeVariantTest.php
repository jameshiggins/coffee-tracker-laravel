<?php

namespace Tests\Unit;

use App\Models\CoffeeVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CoffeeVariantTest extends TestCase
{
    #[DataProvider('priceCases')]
    public function test_price_per_gram_is_price_divided_by_grams_to_4_decimals(int $grams, float $price, float $expected): void
    {
        $variant = new CoffeeVariant(['bag_weight_grams' => $grams, 'price' => $price]);
        $this->assertSame($expected, $variant->price_per_gram);
    }

    public static function priceCases(): array
    {
        return [
            '340g $22.00 → $0.0647'          => [340, 22.00, 0.0647],
            '250g $24.00 → $0.0960'          => [250, 24.00, 0.0960],
            '1000g $58.00 → $0.0580'         => [1000, 58.00, 0.0580],
            'tiny rounding 100g $9.999'      => [100, 9.999, 0.1000],
        ];
    }

    public function test_cents_per_gram_is_price_per_gram_times_100_to_1_decimal(): void
    {
        $variant = new CoffeeVariant(['bag_weight_grams' => 340, 'price' => 22.00]);
        $this->assertSame(6.5, $variant->cents_per_gram);
    }

    public function test_zero_grams_returns_zero_not_division_error(): void
    {
        $variant = new CoffeeVariant(['bag_weight_grams' => 0, 'price' => 10.00]);
        $this->assertSame(0.0, (float) $variant->price_per_gram);
        $this->assertSame(0.0, (float) $variant->cents_per_gram);
    }
}
