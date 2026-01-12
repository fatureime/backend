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

        // Convert logos to base64
        $issuerLogo = $this->convertLogoToBase64($invoice->getIssuer()->getLogo());
        $receiverLogo = $this->convertLogoToBase64($invoice->getReceiver()?->getLogo());

        // Calculate tax totals grouped by rate
        $taxGroups = $this->calculateTaxGroups($invoice);

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
            'issuerLogo' => $issuerLogo,
            'receiverLogo' => $receiverLogo,
            'taxGroups' => $taxGroups,
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

    /**
     * Calculate tax totals grouped by tax rate
     */
    private function calculateTaxGroups(Invoice $invoice): array
    {
        $taxGroups = [];
        
        foreach ($invoice->getItems() as $item) {
            $tax = $item->getTax();
            $taxRate = null;
            $taxLabel = 'E përjashtuar';
            
            if ($tax && $tax->getRate() !== null) {
                $taxRate = $tax->getRate();
                $taxLabel = 'TVSH ' . $taxRate . '%';
            }
            
            $key = $taxRate ?? 'exempt';
            
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'label' => $taxLabel,
                    'rate' => $taxRate,
                    'total' => '0.00',
                ];
            }
            
            $currentTotal = $taxGroups[$key]['total'];
            $itemTaxAmount = $item->getTaxAmount() ?? '0.00';
            $taxGroups[$key]['total'] = (string) bcadd($currentTotal, $itemTaxAmount, 2);
        }
        
        // Sort by rate (exempt last)
        uksort($taxGroups, function($a, $b) {
            if ($a === 'exempt') return 1;
            if ($b === 'exempt') return -1;
            return (float)$a <=> (float)$b;
        });
        
        return $taxGroups;
    }

    /**
     * Convert logo file to base64 for PDF embedding
     * Returns array with 'data' (base64) and 'mime' (MIME type) or null
     */
    private function convertLogoToBase64(?string $logoPath): ?array
    {
        if (!$logoPath) {
            return null;
        }

        try {
            $projectDir = $this->parameterBag->get('kernel.project_dir');
            $fullPath = $projectDir . '/public/' . ltrim($logoPath, '/');
            
            if (!file_exists($fullPath) || !is_file($fullPath)) {
                return null;
            }

            $imageData = file_get_contents($fullPath);
            if ($imageData === false) {
                return null;
            }

            // Determine MIME type from file extension
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
            ];
            
            $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';
            
            return [
                'data' => base64_encode($imageData),
                'mime' => $mimeType,
            ];
        } catch (\Exception $e) {
            // If logo conversion fails, return null
            // Template will handle missing logo gracefully
            return null;
        }
    }
}
