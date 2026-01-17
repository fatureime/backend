<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\InvoiceStatus;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Repository\InvoiceStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InvoiceStatusController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvoiceStatusRepository $invoiceStatusRepository,
        private InvoiceRepository $invoiceRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all invoice statuses (all users of admin tenants can view)
     */
    #[Route('/api/invoice-statuses', name: 'app_invoice_statuses_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listInvoiceStatuses(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsInAdminTenant($user);

        $statuses = $this->invoiceStatusRepository->findAllOrderedByCode();

        $data = array_map(function (InvoiceStatus $status) {
            return $this->serializeInvoiceStatus($status);
        }, $statuses);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single invoice status by ID (all users of admin tenants can view)
     */
    #[Route('/api/invoice-statuses/{id}', name: 'app_invoice_status_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getInvoiceStatus(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsInAdminTenant($user);

        $status = $this->invoiceStatusRepository->find($id);

        if (!$status) {
            return new JsonResponse(
                ['error' => 'Invoice status not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->serializeInvoiceStatus($status), Response::HTTP_OK);
    }

    /**
     * Create a new invoice status (only admins of admin tenants)
     */
    #[Route('/api/invoice-statuses', name: 'app_invoice_status_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createInvoiceStatus(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $data = json_decode($request->getContent(), true);

        // Validate code
        if (!isset($data['code']) || empty(trim($data['code']))) {
            return new JsonResponse(
                ['error' => 'Status code is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $code = trim($data['code']);

        // Check if status with this code already exists
        $existingStatus = $this->invoiceStatusRepository->findByCode($code);
        if ($existingStatus) {
            return new JsonResponse(
                ['error' => 'Invoice status with this code already exists'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $status = new InvoiceStatus();
        $status->setCode($code);

        // Validate entity
        $errors = $this->validator->validate($status);
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

        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeInvoiceStatus($status),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update an invoice status (only admins of admin tenants)
     */
    #[Route('/api/invoice-statuses/{id}', name: 'app_invoice_status_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateInvoiceStatus(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $status = $this->invoiceStatusRepository->find($id);

        if (!$status) {
            return new JsonResponse(
                ['error' => 'Invoice status not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        // Update code if provided
        if (isset($data['code'])) {
            $code = trim($data['code']);
            
            if (empty($code)) {
                return new JsonResponse(
                    ['error' => 'Status code cannot be empty'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Check if another status with this code already exists (excluding current status)
            $existingStatus = $this->invoiceStatusRepository->findByCode($code);
            if ($existingStatus && $existingStatus->getId() !== $status->getId()) {
                return new JsonResponse(
                    ['error' => 'Invoice status with this code already exists'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $status->setCode($code);
        }

        // Validate entity
        $errors = $this->validator->validate($status);
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

        return new JsonResponse($this->serializeInvoiceStatus($status), Response::HTTP_OK);
    }

    /**
     * Delete an invoice status (only admins of admin tenants)
     */
    #[Route('/api/invoice-statuses/{id}', name: 'app_invoice_status_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteInvoiceStatus(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $status = $this->invoiceStatusRepository->find($id);

        if (!$status) {
            return new JsonResponse(
                ['error' => 'Invoice status not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if status is in use by any invoices
        $invoicesUsingStatus = $this->invoiceRepository->createQueryBuilder('i')
            ->andWhere('i.status = :status')
            ->setParameter('status', $status)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (count($invoicesUsingStatus) > 0) {
            return new JsonResponse(
                ['error' => 'Cannot delete invoice status that is in use by invoices'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->entityManager->remove($status);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Invoice status deleted successfully'],
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
     * Ensure user is in an admin tenant (for viewing)
     */
    private function ensureUserIsInAdminTenant(User $user): void
    {
        $this->ensureUserIsActive($user);

        // Check if user's tenant is an admin tenant
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            throw $this->createAccessDeniedException('Only users of admin tenants can view invoice statuses');
        }
    }

    /**
     * Ensure user is admin of admin tenant
     * - User must have ROLE_ADMIN in roles
     * - User's tenant must have isAdmin = true
     */
    private function ensureUserIsAdminOfAdminTenant(User $user): void
    {
        $this->ensureUserIsActive($user);

        // Check if user has admin role
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            throw $this->createAccessDeniedException('Only admin users can manage invoice statuses');
        }

        // Check if user's tenant is an admin tenant
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            throw $this->createAccessDeniedException('Only admins of admin tenants can manage invoice statuses');
        }
    }

    /**
     * Serialize invoice status to array
     */
    private function serializeInvoiceStatus(InvoiceStatus $status): array
    {
        return [
            'id' => $status->getId(),
            'code' => $status->getCode(),
            'created_at' => $status->getCreatedAt()?->format('c'),
            'updated_at' => $status->getUpdatedAt()?->format('c'),
        ];
    }
}
