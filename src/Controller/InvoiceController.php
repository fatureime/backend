<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Business;
use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\Tax;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\BusinessRepository;
use App\Repository\InvoiceItemRepository;
use App\Repository\InvoiceRepository;
use App\Repository\InvoiceStatusRepository;
use App\Repository\TaxRepository;
use App\Service\InvoiceExcelService;
use App\Service\InvoicePdfService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InvoiceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvoiceRepository $invoiceRepository,
        private InvoiceItemRepository $invoiceItemRepository,
        private BusinessRepository $businessRepository,
        private ArticleRepository $articleRepository,
        private TaxRepository $taxRepository,
        private InvoiceStatusRepository $invoiceStatusRepository,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
        private InvoicePdfService $pdfService,
        private InvoiceExcelService $excelService
    ) {
    }


    /**
     * Get all invoices (admin tenants only)
     */
    #[Route('/api/invoices', name: 'app_invoices_all', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getAllInvoices(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        // Only admin tenants can access all invoices
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            return new JsonResponse(
                ['error' => 'Access denied. Only admin tenants can view all invoices.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Get all invoices ordered by creation date (newest first)
        $invoices = $this->invoiceRepository->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $data = array_map(function (Invoice $invoice) use ($request) {
            return $this->serializeInvoice($invoice, $request);
        }, $invoices);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get all invoices for a business (as issuer)
     * Admin tenants can see all invoices across all businesses
     */
    #[Route('/api/businesses/{businessId}/invoices', name: 'app_invoices_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listInvoices(int $businessId, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Admin tenants can see all invoices across all businesses
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $invoices = $this->invoiceRepository->createQueryBuilder('i')
                ->orderBy('i.createdAt', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            $invoices = $this->invoiceRepository->findByIssuerBusiness($business);
        }

        $data = array_map(function (Invoice $invoice) use ($request) {
            return $this->serializeInvoice($invoice, $request);
        }, $invoices);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single invoice by ID
     */
    #[Route('/api/businesses/{businessId}/invoices/{id}', name: 'app_invoice_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getInvoice(int $businessId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Admin tenants can access any invoice by ID
        // Normal tenants can only access invoices issued by the specified business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $this->logger->info('Admin tenant accessing invoice', [
                'user_id' => $user->getId(),
                'invoice_id' => $id,
                'business_id' => $businessId,
            ]);
            $invoice = $this->invoiceRepository->find($id);
        } else {
            $this->logger->info('Normal tenant accessing invoice', [
                'user_id' => $user->getId(),
                'invoice_id' => $id,
                'business_id' => $businessId,
            ]);
            $invoice = $this->invoiceRepository->findByIdAndIssuerBusiness($id, $business);
        }

        if (!$invoice) {
            $this->logger->warning('Invoice not found or access denied', [
                'user_id' => $user->getId(),
                'invoice_id' => $id,
                'business_id' => $businessId,
                'is_admin_tenant' => $user->getTenant() && $user->getTenant()->isAdminTenant(),
            ]);
            // Provide more specific error message
            if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Invoice not found'],
                    Response::HTTP_NOT_FOUND
                );
            } else {
                return new JsonResponse(
                    ['error' => 'Invoice not found or you do not have access to this invoice'],
                    Response::HTTP_NOT_FOUND
                );
            }
        }

        return new JsonResponse($this->serializeInvoice($invoice, $request), Response::HTTP_OK);
    }

    /**
     * Create a new invoice with items
     */
    #[Route('/api/businesses/{businessId}/invoices', name: 'app_invoice_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createInvoice(int $businessId, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // For normal tenants: issuer must be their tenant's business
        // For admin tenants: can use any issuer business
        $issuerBusiness = $business;
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            // Normal tenant: issuer is always their tenant's business
            $issuerBusiness = $user->getTenant()->getIssuerBusiness();
            if (!$issuerBusiness) {
                return new JsonResponse(
                    ['error' => 'Tenant does not have an issuer business'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        } else {
            // Admin tenant: check if user can write to the issuer business's tenant
            // Non-admin users from admin tenants can only create invoices for their own tenant
            $this->ensureUserCanWriteToTenant($user, $issuerBusiness->getTenant());
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['receiver_id']) || !is_numeric($data['receiver_id'])) {
            return new JsonResponse(
                ['error' => 'Receiver business ID is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $receiverBusiness = $this->businessRepository->find((int) $data['receiver_id']);
        if (!$receiverBusiness) {
            return new JsonResponse(
                ['error' => 'Receiver business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        if (!isset($data['invoice_date'])) {
            return new JsonResponse(
                ['error' => 'Invoice date is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($data['due_date'])) {
            return new JsonResponse(
                ['error' => 'Due date is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Create new invoice
        $invoice = new Invoice();
        $invoice->setIssuer($issuerBusiness);
        $invoice->setReceiver($receiverBusiness);
        $invoice->setInvoiceNumber($this->invoiceRepository->generateNextInvoiceNumber($issuerBusiness));

        try {
            $invoice->setInvoiceDate(new \DateTimeImmutable($data['invoice_date']));
            $invoice->setDueDate(new \DateTimeImmutable($data['due_date']));
        } catch (\Exception $e) {
            return new JsonResponse(
                ['error' => 'Invalid date format'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Handle status - default to 'draft' if not provided
        $statusCode = $data['status'] ?? 'draft';
        $invoiceStatus = $this->invoiceStatusRepository->findByCode($statusCode);
        if (!$invoiceStatus) {
            return new JsonResponse(
                ['error' => 'Invalid invoice status: ' . $statusCode],
                Response::HTTP_BAD_REQUEST
            );
        }
        $invoice->setStatus($invoiceStatus);

        // Validate invoice
        $errors = $this->validator->validate($invoice);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(
                ['error' => implode(', ', $errorMessages)],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Handle invoice items
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $itemData) {
                $item = $this->createInvoiceItem($invoice, $itemData, $index);
                if ($item instanceof JsonResponse) {
                    return $item; // Return error response
                }
                $invoice->addItem($item);
            }
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        // Recalculate totals after flush
        $invoice->calculateTotals();
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeInvoice($invoice, $request),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update an invoice and its items
     */
    #[Route('/api/businesses/{businessId}/invoices/{id}', name: 'app_invoice_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateInvoice(int $businessId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Admin tenants can access any invoice by ID
        // Normal tenants can only access invoices issued by the specified business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $invoice = $this->invoiceRepository->find($id);
        } else {
            $invoice = $this->invoiceRepository->findByIdAndIssuerBusiness($id, $business);
        }

        if (!$invoice) {
            // Provide more specific error message
            if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Invoice not found'],
                    Response::HTTP_NOT_FOUND
                );
            } else {
                return new JsonResponse(
                    ['error' => 'Invoice not found or you do not have access to this invoice'],
                    Response::HTTP_NOT_FOUND
                );
            }
        }

        $data = json_decode($request->getContent(), true);

        // For normal tenants: ensure invoice issuer is their tenant's issuer business
        // For admin tenants: can update any invoice, but non-admin users can only update their own tenant's invoices
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            $tenantIssuerBusiness = $user->getTenant()->getIssuerBusiness();
            if (!$tenantIssuerBusiness) {
                return new JsonResponse(
                    ['error' => 'Tenant does not have an issuer business'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            // Ensure the invoice's issuer is the tenant's issuer business
            if ($invoice->getIssuer() !== $tenantIssuerBusiness) {
                return new JsonResponse(
                    ['error' => 'You can only update invoices issued by your tenant\'s business'],
                    Response::HTTP_FORBIDDEN
                );
            }
        } else {
            // Admin tenant: check if user can write to the invoice's issuer business tenant
            // Non-admin users from admin tenants can only update their own tenant's invoices
            $this->ensureUserCanWriteToTenant($user, $invoice->getIssuer()->getTenant());
        }

        // Prevent changing issuer for non-admin tenants
        if (isset($data['issuer_id']) && is_numeric($data['issuer_id'])) {
            if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'You cannot change the issuer business'],
                    Response::HTTP_FORBIDDEN
                );
            }
            // Admin tenants can change issuer, but non-admin users can only change to their own tenant's businesses
            $issuerBusiness = $this->businessRepository->find((int) $data['issuer_id']);
            if (!$issuerBusiness) {
                return new JsonResponse(
                    ['error' => 'Issuer business not found'],
                    Response::HTTP_NOT_FOUND
                );
            }
            // Check if user can write to the new issuer business's tenant
            $this->ensureUserCanWriteToTenant($user, $issuerBusiness->getTenant());
            $invoice->setIssuer($issuerBusiness);
        }

        // Update invoice fields
        if (isset($data['receiver_id']) && is_numeric($data['receiver_id'])) {
            $receiverBusiness = $this->businessRepository->find((int) $data['receiver_id']);
            if (!$receiverBusiness) {
                return new JsonResponse(
                    ['error' => 'Receiver business not found'],
                    Response::HTTP_NOT_FOUND
                );
            }
            $invoice->setReceiver($receiverBusiness);
        }

        if (isset($data['invoice_date'])) {
            try {
                $invoice->setInvoiceDate(new \DateTimeImmutable($data['invoice_date']));
            } catch (\Exception $e) {
                return new JsonResponse(
                    ['error' => 'Invalid invoice date format'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        if (isset($data['due_date'])) {
            try {
                $invoice->setDueDate(new \DateTimeImmutable($data['due_date']));
            } catch (\Exception $e) {
                return new JsonResponse(
                    ['error' => 'Invalid due date format'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        if (isset($data['status'])) {
            $invoiceStatus = $this->invoiceStatusRepository->findByCode($data['status']);
            if (!$invoiceStatus) {
                return new JsonResponse(
                    ['error' => 'Invalid invoice status: ' . $data['status']],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $invoice->setStatus($invoiceStatus);
        }

        // Handle items update
        if (isset($data['items']) && is_array($data['items'])) {
            // Remove all existing items
            foreach ($invoice->getItems() as $item) {
                $this->entityManager->remove($item);
            }
            $invoice->getItems()->clear();

            // Add new items
            foreach ($data['items'] as $index => $itemData) {
                $item = $this->createInvoiceItem($invoice, $itemData, $index);
                if ($item instanceof JsonResponse) {
                    return $item; // Return error response
                }
                $invoice->addItem($item);
            }
        }

        // Validate invoice
        $errors = $this->validator->validate($invoice);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(
                ['error' => implode(', ', $errorMessages)],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->entityManager->flush();

        // Recalculate totals after flush
        $invoice->calculateTotals();
        $this->entityManager->flush();

        return new JsonResponse($this->serializeInvoice($invoice, $request), Response::HTTP_OK);
    }

    /**
     * Delete an invoice (cascades to items)
     */
    #[Route('/api/businesses/{businessId}/invoices/{id}', name: 'app_invoice_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteInvoice(int $businessId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Admin tenants can access any invoice by ID
        // Normal tenants can only access invoices issued by the specified business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $invoice = $this->invoiceRepository->find($id);
        } else {
            $invoice = $this->invoiceRepository->findByIdAndIssuerBusiness($id, $business);
        }

        if (!$invoice) {
            // Provide more specific error message
            if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Invoice not found'],
                    Response::HTTP_NOT_FOUND
                );
            } else {
                return new JsonResponse(
                    ['error' => 'Invoice not found or you do not have access to this invoice'],
                    Response::HTTP_NOT_FOUND
                );
            }
        }

        // Check if user can write to this tenant (non-admin users from admin tenants can only write to their own tenant)
        $this->ensureUserCanWriteToTenant($user, $invoice->getIssuer()->getTenant());

        $this->entityManager->remove($invoice);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Invoice deleted successfully'],
            Response::HTTP_OK
        );
    }

    /**
     * Download invoice as PDF
     */
    #[Route('/api/businesses/{businessId}/invoices/{id}/pdf', name: 'app_invoice_pdf', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function downloadPdf(int $businessId, int $id, Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Admin tenants can access any invoice by ID
        // Normal tenants can only access invoices issued by the specified business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $invoice = $this->invoiceRepository->find($id);
        } else {
            $invoice = $this->invoiceRepository->findByIdAndIssuerBusiness($id, $business);
        }

        if (!$invoice) {
            // Provide more specific error message
            if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Invoice not found'],
                    Response::HTTP_NOT_FOUND
                );
            } else {
                return new JsonResponse(
                    ['error' => 'Invoice not found or you do not have access to this invoice'],
                    Response::HTTP_NOT_FOUND
                );
            }
        }

        // Ensure invoice items are loaded
        $items = $this->invoiceItemRepository->findByInvoice($invoice);
        foreach ($items as $item) {
            $invoice->addItem($item);
        }

        // Generate and return PDF
        return $this->pdfService->generatePdf($invoice);
    }

    /**
     * Export invoices as Excel (for a business)
     */
    #[Route('/api/businesses/{businessId}/invoices/export/excel', name: 'app_invoices_export_excel', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function exportExcel(int $businessId, Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($businessId);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Get status filter from query parameter
        $statusFilter = $request->query->get('status');

        // Get invoices for the business
        $invoices = $this->invoiceRepository->findByIssuerBusiness($business);

        // Apply status filter if provided
        if ($statusFilter && in_array($statusFilter, ['draft', 'sent', 'paid', 'overdue', 'cancelled'])) {
            $invoices = array_filter($invoices, function (Invoice $invoice) use ($statusFilter) {
                return $invoice->getStatusCode() === $statusFilter;
            });
        }

        // Generate and return Excel
        return $this->excelService->generateExcel(array_values($invoices));
    }

    /**
     * Export all invoices as Excel (admin tenants only)
     */
    #[Route('/api/invoices/export/excel', name: 'app_invoices_export_excel_all', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function exportExcelAll(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        // Only admin tenants can access all invoices
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            return new JsonResponse(
                ['error' => 'Access denied. Only admin tenants can export all invoices.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Get optional filters from query parameters
        $statusFilter = $request->query->get('status');
        $businessIdFilter = $request->query->get('businessId');

        // Build query
        $qb = $this->invoiceRepository->createQueryBuilder('i');

        // Apply business filter if provided
        if ($businessIdFilter && is_numeric($businessIdFilter)) {
            $business = $this->businessRepository->find((int) $businessIdFilter);
            if ($business) {
                $qb->andWhere('i.issuer = :business')
                   ->setParameter('business', $business);
            }
        }

        // Apply status filter if provided
        if ($statusFilter && in_array($statusFilter, ['draft', 'sent', 'paid', 'overdue', 'cancelled'])) {
            $statusEntity = $this->invoiceStatusRepository->findByCode($statusFilter);
            if ($statusEntity) {
                $qb->andWhere('i.status = :status')
                   ->setParameter('status', $statusEntity);
            }
        }

        $qb->orderBy('i.createdAt', 'DESC');

        $invoices = $qb->getQuery()->getResult();

        // Generate and return Excel
        return $this->excelService->generateExcel($invoices);
    }

    /**
     * Create an invoice item from data
     */
    private function createInvoiceItem(Invoice $invoice, array $itemData, int $sortOrder): InvoiceItem|JsonResponse
    {
        if (!isset($itemData['description']) || empty(trim($itemData['description']))) {
            return new JsonResponse(
                ['error' => 'Item description is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($itemData['quantity']) || !is_numeric($itemData['quantity'])) {
            return new JsonResponse(
                ['error' => 'Item quantity is required and must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($itemData['unit_price']) || !is_numeric($itemData['unit_price'])) {
            return new JsonResponse(
                ['error' => 'Item unit price is required and must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $item = new InvoiceItem();
        $item->setInvoice($invoice);
        $item->setDescription(trim($itemData['description']));
        $item->setQuantity((string) $itemData['quantity']);
        $item->setUnitPrice((string) $itemData['unit_price']);
        $item->setSortOrder($sortOrder);

        // Handle article reference (optional)
        if (isset($itemData['article_id']) && is_numeric($itemData['article_id'])) {
            $article = $this->articleRepository->find((int) $itemData['article_id']);
            if ($article) {
                // Validate that the article belongs to the invoice's issuer business
                if ($article->getBusiness() !== $invoice->getIssuer()) {
                    return new JsonResponse(
                        ['error' => 'Article must belong to the invoice issuer business'],
                        Response::HTTP_BAD_REQUEST
                    );
                }
                $item->setArticle($article);
            }
        }

        // Handle tax reference (optional)
        if (isset($itemData['tax_id']) && is_numeric($itemData['tax_id'])) {
            $tax = $this->taxRepository->find((int) $itemData['tax_id']);
            if ($tax) {
                $item->setTax($tax);
            }
        } elseif (isset($itemData['tax_rate'])) {
            // Allow specifying tax by rate
            $taxRate = $itemData['tax_rate'] === null ? null : (float) $itemData['tax_rate'];
            $tax = $this->taxRepository->findByRate($taxRate);
            if ($tax) {
                $item->setTax($tax);
            }
        }

        // Calculate totals
        $item->calculateTotals();

        // Validate item
        $errors = $this->validator->validate($item);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(
                ['error' => 'Item validation failed: ' . implode(', ', $errorMessages)],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $item;
    }

    /**
     * Ensure user is active
     */
    private function ensureUserIsActive(User $user): void
    {
        if (!$user->isActive()) {
            throw $this->createAccessDeniedException('User account is inactive');
        }
    }

    /**
     * Ensure user can access business
     * - Admin tenants can access any business
     * - Regular users can only access businesses of their tenant
     */
    private function ensureUserCanAccessBusiness(User $user, Business $business): void
    {
        $this->ensureUserIsActive($user);

        // Admin tenants can access any business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            return;
        }

        // Regular users can only access businesses of their tenant
        if ($user->getTenant() !== $business->getTenant()) {
            throw $this->createAccessDeniedException('You do not have access to this business');
        }
    }

    /**
     * Check if user can read all entities (admin tenant)
     */
    private function canUserReadAll(User $user): bool
    {
        return $user->getTenant() && $user->getTenant()->isAdminTenant();
    }

    /**
     * Check if user can write to a specific tenant
     */
    private function canUserWriteToTenant(User $user, ?Tenant $targetTenant): bool
    {
        if (!$targetTenant) {
            return false;
        }

        // Admin users from admin tenants can write to any tenant
        if ($this->canUserReadAll($user) && in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }
        
        // Non-admin users from admin tenants can only write to their own tenant
        // Regular tenants can only write to their own tenant
        return $user->getTenant() === $targetTenant;
    }

    /**
     * Ensure user can write to a specific tenant (throws exception if not)
     */
    private function ensureUserCanWriteToTenant(User $user, ?Tenant $targetTenant): void
    {
        if (!$this->canUserWriteToTenant($user, $targetTenant)) {
            throw $this->createAccessDeniedException('You do not have permission to modify this tenant\'s entities');
        }
    }

    /**
     * Serialize invoice to array
     */
    private function serializeInvoice(Invoice $invoice, ?Request $request = null): array
    {
        $issuer = $invoice->getIssuer();
        $receiver = $invoice->getReceiver();
        $items = $this->invoiceItemRepository->findByInvoice($invoice);

        return [
            'id' => $invoice->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'invoice_date' => $invoice->getInvoiceDate()?->format('Y-m-d'),
            'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
            'status' => $invoice->getStatusCode(),
            'subtotal' => $invoice->getSubtotal(),
            'total' => $invoice->getTotal(),
            'issuer_id' => $issuer?->getId(),
            'receiver_id' => $receiver?->getId(),
            'created_at' => $invoice->getCreatedAt()?->format('c'),
            'updated_at' => $invoice->getUpdatedAt()?->format('c'),
            'issuer' => $issuer ? $this->serializeBusinessForInvoice($issuer, $request) : null,
            'receiver' => $receiver ? $this->serializeBusinessForInvoice($receiver, $request) : null,
            'items' => array_map(function (InvoiceItem $item) {
                return $this->serializeInvoiceItem($item);
            }, $items),
        ];
    }

    /**
     * Serialize business for invoice (includes all fields needed for PDF)
     */
    private function serializeBusinessForInvoice(Business $business, ?Request $request = null): array
    {
        $logoUrl = null;
        if ($business->getLogo()) {
            $logoUrl = $this->getLogoUrl($business->getLogo(), $request);
        }

        return [
            'id' => $business->getId(),
            'business_name' => $business->getBusinessName(),
            'trade_name' => $business->getTradeName(),
            'business_type' => $business->getBusinessType(),
            'unique_identifier_number' => $business->getUniqueIdentifierNumber(),
            'vat_number' => $business->getVatNumber(),
            'municipality' => $business->getMunicipality(),
            'address' => $business->getAddress(),
            'phone' => $business->getPhone(),
            'email' => $business->getEmail(),
            'logo' => $logoUrl,
        ];
    }

    /**
     * Get logo URL for business
     */
    private function getLogoUrl(?string $logoPath, ?Request $request = null): ?string
    {
        if (!$logoPath) {
            return null;
        }

        if ($request) {
            $baseUrl = $request->getSchemeAndHttpHost();
            return $baseUrl . '/uploads/logos/' . basename($logoPath);
        }

        return '/uploads/logos/' . basename($logoPath);
    }

    /**
     * Serialize invoice item to array
     */
    private function serializeInvoiceItem(InvoiceItem $item): array
    {
        $article = $item->getArticle();
        $tax = $item->getTax();

        return [
            'id' => $item->getId(),
            'description' => $item->getDescription(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'subtotal' => $item->getSubtotal(),
            'tax_amount' => $item->getTaxAmount(),
            'total' => $item->getTotal(),
            'sort_order' => $item->getSortOrder(),
            'article_id' => $article?->getId(),
            'tax_id' => $tax?->getId(),
            'created_at' => $item->getCreatedAt()?->format('c'),
            'updated_at' => $item->getUpdatedAt()?->format('c'),
            'article' => $article ? [
                'id' => $article->getId(),
                'name' => $article->getName(),
                'unit_price' => $article->getUnitPrice(),
            ] : null,
            'tax' => $tax ? [
                'id' => $tax->getId(),
                'rate' => $tax->getRate(),
                'name' => $tax->getName(),
            ] : null,
        ];
    }
}
