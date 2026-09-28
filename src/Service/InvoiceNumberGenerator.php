<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\InvoiceRepository;

/** Generates sequential numbers per company and year: INV-2026-0001, INV-2026-0002, … */
class InvoiceNumberGenerator
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    public function next(Company $company, ?\DateTimeInterface $date = null): string
    {
        $prefix = $company->getInvoicePrefix();
        $year = ($date ?? new \DateTimeImmutable())->format('Y');
        $prefix = trim($prefix, " -");
        $base = ($prefix !== '' ? $prefix.'-' : '').$year.'-';

        $last = $this->invoices->findLastNumberWithPrefix($company, $base);
        $next = 1;
        if ($last !== null && preg_match('/(\d+)$/', substr($last, strlen($base)), $m)) {
            $next = (int) $m[1] + 1;
        }

        return $base.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
