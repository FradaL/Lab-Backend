<?php

namespace Tests\Unit\Services\LaboratoryOrders;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicCalculator;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicTotals;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaboratoryOrderEconomicCalculatorTest extends TestCase
{
    private LaboratoryOrderEconomicCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LaboratoryOrderEconomicCalculator;
    }

    #[DataProvider('totalsProvider')]
    public function test_it_calculates_exact_totals(
        array $prices,
        ?string $discountType,
        ?string $discountValue,
        array $expected,
    ): void {
        $result = $this->calculator->calculate(
            $this->order($discountType, $discountValue),
            $this->lines($prices),
        );

        $this->assertInstanceOf(LaboratoryOrderEconomicTotals::class, $result);
        $this->assertSame($expected, [
            $result->subtotal,
            $result->discount,
            $result->taxes,
            $result->total,
        ]);
    }

    /** @return array<string, array{list<string>, ?string, ?string, list<string>}> */
    public static function totalsProvider(): array
    {
        return [
            'empty' => [[], null, null, ['0.00', '0.00', '0.00', '0.00']],
            'single line' => [['35.00'], null, null, ['35.00', '0.00', '0.00', '35.00']],
            'multiple lines' => [['35.00', '75.00', '30.00'], null, null, ['140.00', '0.00', '0.00', '140.00']],
            'repeated exams' => [['35.00', '35.00'], null, null, ['70.00', '0.00', '0.00', '70.00']],
            'zero price' => [['0.00', '35.00'], null, null, ['35.00', '0.00', '0.00', '35.00']],
            'no float drift' => [['0.10', '0.20', '0.30'], null, null, ['0.60', '0.00', '0.00', '0.60']],
            'percentage ten' => [['100.00', '100.00'], 'percentage', '10.00', ['200.00', '20.00', '0.00', '180.00']],
            'percentage decimal' => [['200.00'], 'percentage', '12.50', ['200.00', '25.00', '0.00', '175.00']],
            'percentage decimal with fractional cent' => [['199.99'], 'percentage', '12.50', ['199.99', '25.00', '0.00', '174.99']],
            'percentage one hundred' => [['150.00'], 'percentage', '100.00', ['150.00', '150.00', '0.00', '0.00']],
            'half up' => [['10.05'], 'percentage', '10.00', ['10.05', '1.01', '0.00', '9.04']],
            'half up below boundary' => [['10.04'], 'percentage', '12.50', ['10.04', '1.26', '0.00', '8.78']],
            'amount' => [['200.00'], 'amount', '25.00', ['200.00', '25.00', '0.00', '175.00']],
            'amount equals subtotal' => [['100.00'], 'amount', '100.00', ['100.00', '100.00', '0.00', '0.00']],
            'amount above subtotal' => [['70.00'], 'amount', '100.00', ['70.00', '70.00', '0.00', '0.00']],
            'large values' => [['4999999999.99', '5000000000.00'], null, null, ['9999999999.99', '0.00', '0.00', '9999999999.99']],
        ];
    }

    public function test_amount_intent_recovers_when_subtotal_grows_without_mutating_the_order(): void
    {
        $order = $this->order('amount', '100.00');

        $first = $this->calculator->calculate($order, $this->lines(['70.00']));
        $second = $this->calculator->calculate($order, $this->lines(['150.00']));

        $this->assertSame(['70.00', '0.00'], [$first->discount, $first->total]);
        $this->assertSame(['100.00', '50.00'], [$second->discount, $second->total]);
        $this->assertSame('100.00', $order->discount_value);
    }

    public function test_it_ignores_persisted_totals_and_taxes_and_does_not_mutate_inputs(): void
    {
        $order = $this->order(null, null, [
            'subtotal' => '100.00',
            'discount' => '15.00',
            'taxes' => '12.00',
            'total' => '97.00',
        ]);
        $lines = $this->lines(['100.00']);
        $orderBefore = $order->getAttributes();
        $linesBefore = array_map(fn (LaboratoryOrderExam $line): array => $line->getAttributes(), $lines);

        $result = $this->calculator->calculate($order, $lines);

        $this->assertSame(['100.00', '0.00', '0.00', '100.00'], [
            $result->subtotal,
            $result->discount,
            $result->taxes,
            $result->total,
        ]);
        $this->assertSame($orderBefore, $order->getAttributes());
        $this->assertSame($linesBefore, array_map(fn (LaboratoryOrderExam $line): array => $line->getAttributes(), $lines));
    }

    #[DataProvider('invalidIntentProvider')]
    public function test_it_rejects_invalid_discount_intent(?string $type, ?string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate($this->order($type, $value), []);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function invalidIntentProvider(): array
    {
        return [
            'unknown type' => ['foo', '10.00'],
            'null type with value' => [null, '10.00'],
            'percentage without value' => ['percentage', null],
            'amount without value' => ['amount', null],
            'percentage zero' => ['percentage', '0.00'],
            'percentage negative' => ['percentage', '-1.00'],
            'percentage above one hundred' => ['percentage', '100.01'],
            'amount zero' => ['amount', '0.00'],
            'amount negative' => ['amount', '-1.00'],
        ];
    }

    public function test_it_fails_explicitly_when_subtotal_exceeds_the_persistent_domain(): void
    {
        $this->expectException(OverflowException::class);

        $this->calculator->calculate(
            $this->order(null, null),
            $this->lines(['9999999999.99', '0.01']),
        );
    }

    /** @param array<string, string|null> $economics */
    private function order(?string $type, ?string $value, array $economics = []): LaboratoryOrder
    {
        $order = new LaboratoryOrder;
        $order->setRawAttributes(array_merge([
            'discount_type' => $type,
            'discount_value' => $value,
            'subtotal' => '0.00',
            'discount' => '0.00',
            'taxes' => '0.00',
            'total' => '0.00',
        ], $economics), true);

        return $order;
    }

    /** @param list<string> $prices
     * @return list<LaboratoryOrderExam>
     */
    private function lines(array $prices): array
    {
        return array_map(function (string $price): LaboratoryOrderExam {
            $line = new LaboratoryOrderExam;
            $line->setRawAttributes(['unit_price' => $price], true);

            return $line;
        }, $prices);
    }
}
