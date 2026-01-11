<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\Tax;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\InvoiceItemRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InvoiceItemController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvoiceRepository $invoiceRepository,
        private InvoiceItemRepository $invoiceItemRepository,
        private ArticleRepository $articleRepository,
        private TaxRepository $taxRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all items for an invoice
     */
    #[Route('/api/invoices/{invoiceId}/items', name: 'app_invoice_items_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listItems(int $invoiceId, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $items = $this->invoiceItemRepository->findByInvoice($invoice);

        $data = array_map(function (InvoiceItem $item) {
            return $this->serializeInvoiceItem($item);
        }, $items);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single invoice item by ID
     */
    #[Route('/api/invoices/{invoiceId}/items/{id}', name: 'app_invoice_item_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getItem(int $invoiceId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $item = $this->invoiceItemRepository->findByIdAndInvoice($id, $invoice);

        if (!$item) {
            return new JsonResponse(
                ['error' => 'Invoice item not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->serializeInvoiceItem($item), Response::HTTP_OK);
    }

    /**
     * Create a new invoice item
     */
    #[Route('/api/invoices/{invoiceId}/items', name: 'app_invoice_item_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createItem(int $invoiceId, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $data = json_decode($request->getContent(), true);

        if (!isset($data['description']) || empty(trim($data['description']))) {
            return new JsonResponse(
                ['error' => 'Item description is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($data['quantity']) || !is_numeric($data['quantity'])) {
            return new JsonResponse(
                ['error' => 'Item quantity is required and must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($data['unit_price']) || !is_numeric($data['unit_price'])) {
            return new JsonResponse(
                ['error' => 'Item unit price is required and must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Get next sort order
        $existingItems = $this->invoiceItemRepository->findByInvoice($invoice);
        $sortOrder = count($existingItems);

        $item = new InvoiceItem();
        $item->setInvoice($invoice);
        $item->setDescription(trim($data['description']));
        $item->setQuantity((string) $data['quantity']);
        $item->setUnitPrice((string) $data['unit_price']);
        $item->setSortOrder($sortOrder);

        // Handle article reference (optional)
        if (isset($data['article_id']) && is_numeric($data['article_id'])) {
            $article = $this->articleRepository->find((int) $data['article_id']);
            if ($article) {
                $item->setArticle($article);
            }
        }

        // Handle tax reference (optional)
        if (isset($data['tax_id']) && is_numeric($data['tax_id'])) {
            $tax = $this->taxRepository->find((int) $data['tax_id']);
            if ($tax) {
                $item->setTax($tax);
            }
        } elseif (isset($data['tax_rate'])) {
            // Allow specifying tax by rate
            $taxRate = $data['tax_rate'] === null ? null : (float) $data['tax_rate'];
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

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        // Recalculate invoice totals
        $invoice->calculateTotals();
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeInvoiceItem($item),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update an invoice item
     */
    #[Route('/api/invoices/{invoiceId}/items/{id}', name: 'app_invoice_item_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateItem(int $invoiceId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $item = $this->invoiceItemRepository->findByIdAndInvoice($id, $invoice);

        if (!$item) {
            return new JsonResponse(
                ['error' => 'Invoice item not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        // Update fields if provided
        if (isset($data['description'])) {
            $item->setDescription(trim($data['description']));
        }
        if (isset($data['quantity'])) {
            if (!is_numeric($data['quantity'])) {
                return new JsonResponse(
                    ['error' => 'Quantity must be a number'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $item->setQuantity((string) $data['quantity']);
        }
        if (isset($data['unit_price'])) {
            if (!is_numeric($data['unit_price'])) {
                return new JsonResponse(
                    ['error' => 'Unit price must be a number'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $item->setUnitPrice((string) $data['unit_price']);
        }
        if (isset($data['sort_order'])) {
            $item->setSortOrder((int) $data['sort_order']);
        }

        // Handle article reference
        if (isset($data['article_id'])) {
            if ($data['article_id'] === null) {
                $item->setArticle(null);
            } elseif (is_numeric($data['article_id'])) {
                $article = $this->articleRepository->find((int) $data['article_id']);
                if ($article) {
                    $item->setArticle($article);
                }
            }
        }

        // Handle tax reference
        if (isset($data['tax_id'])) {
            if ($data['tax_id'] === null) {
                $item->setTax(null);
            } elseif (is_numeric($data['tax_id'])) {
                $tax = $this->taxRepository->find((int) $data['tax_id']);
                if ($tax) {
                    $item->setTax($tax);
                }
            }
        } elseif (isset($data['tax_rate'])) {
            $taxRate = $data['tax_rate'] === null ? null : (float) $data['tax_rate'];
            $tax = $this->taxRepository->findByRate($taxRate);
            $item->setTax($tax);
        }

        // Recalculate totals
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

        $this->entityManager->flush();

        // Recalculate invoice totals
        $invoice->calculateTotals();
        $this->entityManager->flush();

        return new JsonResponse($this->serializeInvoiceItem($item), Response::HTTP_OK);
    }

    /**
     * Delete an invoice item
     */
    #[Route('/api/invoices/{invoiceId}/items/{id}', name: 'app_invoice_item_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteItem(int $invoiceId, int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $item = $this->invoiceItemRepository->findByIdAndInvoice($id, $invoice);

        if (!$item) {
            return new JsonResponse(
                ['error' => 'Invoice item not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        // Recalculate invoice totals
        $invoice->calculateTotals();
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Invoice item deleted successfully'],
            Response::HTTP_OK
        );
    }

    /**
     * Reorder invoice items
     */
    #[Route('/api/invoices/{invoiceId}/items/reorder', name: 'app_invoice_items_reorder', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function reorderItems(int $invoiceId, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new JsonResponse(
                ['error' => 'Invoice not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access the invoice's issuer business
        $this->ensureUserCanAccessBusiness($user, $invoice->getIssuer());

        $data = json_decode($request->getContent(), true);

        if (!isset($data['item_ids']) || !is_array($data['item_ids'])) {
            return new JsonResponse(
                ['error' => 'item_ids array is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $items = $this->invoiceItemRepository->findByInvoice($invoice);
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item->getId()] = $item;
        }

        // Validate that all provided IDs exist
        foreach ($data['item_ids'] as $itemId) {
            if (!isset($itemsById[$itemId])) {
                return new JsonResponse(
                    ['error' => "Invoice item with ID {$itemId} not found"],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Validate that all items are included
        if (count($data['item_ids']) !== count($items)) {
            return new JsonResponse(
                ['error' => 'All invoice items must be included in the reorder'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Update sort orders
        foreach ($data['item_ids'] as $index => $itemId) {
            $itemsById[$itemId]->setSortOrder($index);
        }

        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Items reordered successfully'],
            Response::HTTP_OK
        );
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
    private function ensureUserCanAccessBusiness(User $user, $business): void
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
