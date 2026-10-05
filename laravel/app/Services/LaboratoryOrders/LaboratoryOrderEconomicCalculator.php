<?php

namespace App\Services\LaboratoryOrders;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use InvalidArgumentException;
use OverflowException;

final class LaboratoryOrderEconomicCalculator
{
    private const MAX_CENTS = 999_999_999_999;

    /**
     * @param  iterable<LaboratoryOrderExam>  $lines
     */
    public function calculate(
        LaboratoryOrder $order,
        iterable $lines,
    ): LaboratoryOrderEconomicTotals {
        $discountValue = $this->validatedDiscountValue(
            $order->discount_type,
            $order->discount_value,
        );
        $subtotal = 0;

        foreach ($lines as $line) {
            if (! $line instanceof LaboratoryOrderExam) {
                throw new InvalidArgumentException('Every line must be a LaboratoryOrderExam.');
            }

            $linePrice = $this->decimalToCents($line->unit_price, 'unit_price');

            if ($subtotal > self::MAX_CENTS - $linePrice) {
                throw new OverflowException('The subtotal exceeds the NUMERIC(12,2) monetary domain.');
            }

            $subtotal += $linePrice;
        }

        $discount = match ($order->discount_type) {
            null => 0,
            LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE => intdiv(
                ($subtotal * $discountValue) + 5_000,
                10_000,
            ),
            LaboratoryOrder::DISCOUNT_TYPE_AMOUNT => min($discountValue, $subtotal),
        };
        $taxes = 0;

        return new LaboratoryOrderEconomicTotals(
            subtotal: $this->formatCents($subtotal),
            discount: $this->formatCents($discount),
            taxes: $this->formatCents($taxes),
            total: $this->formatCents($subtotal - $discount + $taxes),
        );
    }

    private function validatedDiscountValue(mixed $type, mixed $value): int
    {
        if ($type === null) {
            if ($value !== null) {
                throw new InvalidArgumentException('A discount value requires a discount type.');
            }

            return 0;
        }

        if (! is_string($type) || ! is_string($value)) {
            throw new InvalidArgumentException('A configured discount requires a decimal string value.');
        }

        $valueInHundredths = $this->decimalToCents($value, 'discount_value');

        return match ($type) {
            LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE => $this->validatedPercentage($valueInHundredths),
            LaboratoryOrder::DISCOUNT_TYPE_AMOUNT => $this->validatedAmount($valueInHundredths),
            default => throw new InvalidArgumentException('The discount type is invalid.'),
        };
    }

    private function validatedPercentage(int $value): int
    {
        if ($value <= 0 || $value > 10_000) {
            throw new InvalidArgumentException('A percentage discount must be greater than 0 and at most 100.');
        }

        return $value;
    }

    private function validatedAmount(int $value): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('An amount discount must be greater than 0.');
        }

        return $value;
    }

    private function decimalToCents(mixed $value, string $field): int
    {
        if (! is_string($value) || preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal string with at most two decimals.");
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 10 || (strlen($whole) === 10 && $whole > '9999999999')) {
            throw new OverflowException("{$field} exceeds the NUMERIC(12,2) monetary domain.");
        }

        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        if ($cents > self::MAX_CENTS) {
            throw new OverflowException("{$field} exceeds the NUMERIC(12,2) monetary domain.");
        }

        return $cents;
    }

    private function formatCents(int $value): string
    {
        return sprintf('%d.%02d', intdiv($value, 100), $value % 100);
    }
}
