<?php

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class InvoicePdfService
{
    public function __construct(
        private Environment $twig
    ) {
    }

    /**
     * Generate PDF from invoice
     */
    public function generatePdf(Invoice $invoice): Response
    {
        // Configure DomPDF options
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);

        // Create DomPDF instance
        $dompdf = new Dompdf($options);

        // Render the template
        $html = $this->twig->render('invoice/pdf.html.twig', [
            'invoice' => $invoice,
        ]);

        // Load HTML into DomPDF
        $dompdf->loadHtml($html);

        // Set paper size and orientation
        $dompdf->setPaper('A4', 'portrait');

        // Render PDF
        $dompdf->render();

        // Generate PDF output
        $output = $dompdf->output();

        // Create response
        $response = new Response($output);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="invoice-%s.pdf"',
            $this->sanitizeFilename($invoice->getInvoiceNumber())
        ));
        $response->headers->set('Cache-Control', 'private, max-age=0');

        return $response;
    }

    /**
     * Sanitize filename to prevent path traversal
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove any characters that could be problematic in filenames
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $filename);
        return $filename;
    }

    /**
     * Format currency for display
     */
    public function formatCurrency(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '') . ' €';
    }

    /**
     * Format date for display
     */
    public function formatDate(?\DateTimeImmutable $date): string
    {
        if (!$date) {
            return '';
        }
        return $date->format('d.m.Y');
    }
}
