<?php

namespace App\Entity;

/**
 * How VAT is applied relative to the NHIL / GETFund / COVID-19 levies.
 *
 *  - SIMPLE   (VAT Act 2025, Act 1151 — from 1 Jan 2026):
 *             NHIL, GETFund and VAT are all charged on the same taxable value.
 *             15% + 2.5% + 2.5% = 20% effective.
 *
 *  - COMPOUND (pre-2026 method):
 *             Levies are charged on the taxable value first, then VAT is charged
 *             on (taxable value + levies). 15% × 1.06 + 6% = 21.9% effective
 *             when the 1% COVID-19 levy applied.
 */
final class TaxMode
{
    public const SIMPLE = 'simple';
    public const COMPOUND = 'compound';

    public static function choices(): array
    {
        return [
            'Current (2026+): VAT, NHIL & GETFund on the same base' => self::SIMPLE,
            'Pre-2026: VAT on (value + levies)' => self::COMPOUND,
        ];
    }
}
