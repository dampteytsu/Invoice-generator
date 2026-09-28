<?php

namespace App\Service;

/** "One Thousand Two Hundred Ghana Cedis Fifty Pesewas Only" style text. */
class AmountInWords
{
    private const UNITS = [
        'GHS' => ['Ghana Cedis', 'Pesewas'],
        'USD' => ['US Dollars', 'Cents'],
        'EUR' => ['Euros', 'Cents'],
        'GBP' => ['Pounds Sterling', 'Pence'],
        'NGN' => ['Naira', 'Kobo'],
    ];

    public function convert(string|float $amount, string $currency = 'GHS'): string
    {
        $minor = (int) round((float) $amount * 100);
        $major = intdiv($minor, 100);
        $cents = $minor % 100;
        [$majorName, $minorName] = self::UNITS[strtoupper($currency)] ?? [strtoupper($currency), 'Cents'];

        $text = $this->spell($major).' '.$majorName;
        if ($cents > 0) {
            $text .= ' '.$this->spell($cents).' '.$minorName;
        }

        return $text.' Only';
    }

    private function spell(int $n): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
            $words = (string) $f->format($n);
        } else {
            $words = $this->fallback($n);
        }

        return ucwords(str_replace('-', ' ', $words));
    }

    private function fallback(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)].($n % 10 ? ' '.$ones[$n % 10] : '');
        }
        if ($n < 1000) {
            return $ones[intdiv($n, 100)].' hundred'.($n % 100 ? ' and '.$this->fallback($n % 100) : '');
        }
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $word) {
            if ($n >= $size) {
                return $this->fallback(intdiv($n, $size)).' '.$word.($n % $size ? ' '.$this->fallback($n % $size) : '');
            }
        }

        return (string) $n;
    }
}
