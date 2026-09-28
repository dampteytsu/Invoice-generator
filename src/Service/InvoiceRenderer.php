<?php

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** Renders an invoice to HTML (screen/print) or PDF (Dompdf). */
class InvoiceRenderer
{
    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly AmountInWords $words,
        private readonly CurrentUser $current,
        private readonly string $logoDir,
    ) {
    }

    public function html(Invoice $invoice, bool $forPdf = false): string
    {
        $company = $invoice->getCompany();

        return $this->twig->render('invoice/document.html.twig', [
            'invoice' => $invoice,
            'company' => $company,
            'logo' => $this->logoDataUri($company->getLogo()),
            'amount_in_words' => $this->words->convert($invoice->getTotal(), $invoice->getCurrency()),
            'for_pdf' => $forPdf,
            // The super admin can open any invoice, but never sees full payment details.
            'mask_payment_details' => $this->current->isSuperAdmin()
                && $this->current->company()?->getId() !== $company->getId(),
        ]);
    }

    public function pdf(Invoice $invoice): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($invoice, true), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Embeds the logo so it works identically in the browser and in Dompdf. */
    private function logoDataUri(?string $filename): ?string
    {
        if (!$filename) {
            return null;
        }
        $path = rtrim($this->logoDir, '/').'/'.basename($filename);
        if (!is_file($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
