<?php

namespace App\Controller;

use App\Repository\InvoiceRepository;
use App\Security\TenantVoter;
use App\Service\InvoiceRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceRenderer $renderer,
    ) {
    }

    #[Route('/invoice/{id}', name: 'invoice_print', requirements: ['id' => '\d+'])]
    public function print(int $id): Response
    {
        return new Response($this->renderer->html($this->find($id)));
    }

    #[Route('/invoice/{id}/pdf', name: 'invoice_pdf', requirements: ['id' => '\d+'])]
    public function pdf(int $id, Request $request): Response
    {
        $invoice = $this->find($id);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', 'Invoice-'.$invoice->getInvoiceNumber()).'.pdf';
        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;

        return new Response($this->renderer->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename),
        ]);
    }

    private function find(int $id): \App\Entity\Invoice
    {
        $invoice = $this->invoices->find($id) ?? throw $this->createNotFoundException('Invoice not found.');
        // Another company's invoice looks exactly like a missing one.
        if (!$this->isGranted(TenantVoter::ACCESS, $invoice)) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        return $invoice;
    }
}
