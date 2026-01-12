<?php

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class InvoicePdfService
{
    public function __construct(
        private Environment $twig,
        private ParameterBagInterface $parameterBag
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

        // Calculate tax total from invoice items
        $taxTotal = $this->calculateTaxTotal($invoice);

        // Generate QR code
        $qrCodeBase64 = $this->generateQrCode($invoice);

        // Get frontend URL
        $frontendUrl = $this->parameterBag->get('frontend_url');
        $invoiceUrl = sprintf(
            '%s/businesses/%d/invoices/%d',
            rtrim($frontendUrl, '/'),
            $invoice->getIssuer()->getId(),
            $invoice->getId()
        );

        // Render the template
        $html = $this->twig->render('invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'taxTotal' => $taxTotal,
            'qrCodeBase64' => $qrCodeBase64,
            'invoiceUrl' => $invoiceUrl,
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
     * Calculate total tax amount from invoice items
     */
    private function calculateTaxTotal(Invoice $invoice): string
    {
        $taxTotal = '0.00';
        
        foreach ($invoice->getItems() as $item) {
            $itemTaxAmount = $item->getTaxAmount() ?? '0.00';
            $taxTotal = (string) (bcadd($taxTotal, $itemTaxAmount, 2));
        }
        
        return $taxTotal;
    }

    /**
     * Generate QR code for invoice URL
     */
    private function generateQrCode(Invoice $invoice): ?string
    {
        try {
            $frontendUrl = $this->parameterBag->get('frontend_url');
            $invoiceUrl = sprintf(
                '%s/businesses/%d/invoices/%d',
                rtrim($frontendUrl, '/'),
                $invoice->getIssuer()->getId(),
                $invoice->getId()
            );

            $qrCode = QrCode::create($invoiceUrl)
                ->setSize(200)
                ->setMargin(10);

            $writer = new PngWriter();
            $result = $writer->write($qrCode);
            
            return base64_encode($result->getString());
        } catch (\Exception $e) {
            // If QR code generation fails, return null
            // Template will handle missing QR code gracefully
            return null;
        }
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
