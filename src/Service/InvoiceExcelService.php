<?php

namespace App\Service;

use App\Entity\Invoice;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceExcelService
{
    /**
     * Generate Excel file from invoices array
     */
    public function generateExcel(array $invoices): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Faturat');

        // Set headers
        $headers = [
            'A' => 'Numri i Faturës',
            'B' => 'Data',
            'C' => 'Data e Maturimit',
            'D' => 'Fatura Nga',
            'E' => 'Fatura Për',
            'F' => 'Statusi',
            'G' => 'Pa TVSH',
            'H' => 'TVSH',
            'I' => 'Me TVSH / Totali',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . '1', $header);
        }

        // Style header row
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

        // Populate data rows
        $row = 2;
        foreach ($invoices as $invoice) {
            $issuer = $invoice->getIssuer();
            $receiver = $invoice->getReceiver();
            
            $invoiceDate = $invoice->getInvoiceDate() ? $invoice->getInvoiceDate()->format('Y-m-d') : '';
            $dueDate = $invoice->getDueDate() ? $invoice->getDueDate()->format('Y-m-d') : '';
            
            $subtotal = (float) $invoice->getSubtotal();
            $total = (float) $invoice->getTotal();
            $vatAmount = $total - $subtotal;

            // Map status to Albanian labels
            $statusLabels = [
                'draft' => 'Draft',
                'sent' => 'Dërguar',
                'paid' => 'Paguar',
                'overdue' => 'Vonuar',
                'cancelled' => 'Anuluar',
            ];
            $statusLabel = $statusLabels[$invoice->getStatus()] ?? $invoice->getStatus();

            $sheet->setCellValue('A' . $row, $invoice->getInvoiceNumber());
            $sheet->setCellValue('B' . $row, $invoiceDate);
            $sheet->setCellValue('C' . $row, $dueDate);
            $sheet->setCellValue('D' . $row, $issuer ? $issuer->getBusinessName() : 'N/A');
            $sheet->setCellValue('E' . $row, $receiver ? $receiver->getBusinessName() : 'N/A');
            $sheet->setCellValue('F' . $row, $statusLabel);
            $sheet->setCellValue('G' . $row, $subtotal);
            $sheet->setCellValue('H' . $row, $vatAmount);
            $sheet->setCellValue('I' . $row, $total);

            // Format financial columns as numbers with 2 decimal places
            $sheet->getStyle('G' . $row . ':I' . $row)->getNumberFormat()
                ->setFormatCode('#,##0.00');

            $row++;
        }

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(25);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(18);

        // Add borders to data rows
        if ($row > 2) {
            $dataRange = 'A2:I' . ($row - 1);
            $borderStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ];
            $sheet->getStyle($dataRange)->applyFromArray($borderStyle);
        }

        // Generate filename with timestamp
        $filename = 'faturat-' . date('Y-m-d-His') . '.xlsx';

        // Create streamed response
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s"',
            $this->sanitizeFilename($filename)
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
}
