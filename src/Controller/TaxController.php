<?php

namespace App\Controller;

use App\Entity\InvoiceItem;
use App\Entity\Tax;
use App\Entity\User;
use App\Repository\InvoiceItemRepository;
use App\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TaxController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaxRepository $taxRepository,
        private InvoiceItemRepository $invoiceItemRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all taxes (all authenticated users)
     */
    #[Route('/api/taxes', name: 'app_taxes_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listTaxes(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $taxes = $this->taxRepository->findAll();

        $data = array_map(function (Tax $tax) {
            return $this->serializeTax($tax);
        }, $taxes);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single tax by ID (all authenticated users)
     */
    #[Route('/api/taxes/{id}', name: 'app_tax_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getTax(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $tax = $this->taxRepository->find($id);

        if (!$tax) {
            return new JsonResponse(
                ['error' => 'Tax not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->serializeTax($tax), Response::HTTP_OK);
    }

    /**
     * Create a new tax (only admins of admin tenants)
     */
    #[Route('/api/taxes', name: 'app_tax_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createTax(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $data = json_decode($request->getContent(), true);

        // Validate rate
        if (!isset($data['rate'])) {
            return new JsonResponse(
                ['error' => 'Tax rate is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $rate = $data['rate'] === null ? null : (float) $data['rate'];
        
        // Validate rate value (must be null, 0, 8, or 19)
        if ($rate !== null && !in_array($rate, [0, 8, 19], true)) {
            return new JsonResponse(
                ['error' => 'Tax rate must be null (exempted), 0, 8, or 19'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Check if tax with this rate already exists
        $existingTax = $this->taxRepository->findByRate($rate);
        if ($existingTax) {
            return new JsonResponse(
                ['error' => 'Tax with this rate already exists'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $tax = new Tax();
        $tax->setRate($rate === null ? null : (string) $rate);
        
        if (isset($data['name'])) {
            $tax->setName(trim($data['name']) ?: null);
        } else {
            // Auto-generate name if not provided
            if ($rate === null) {
                $tax->setName('Exempted');
            } else {
                $tax->setName($rate . '%');
            }
        }

        // Validate entity
        $errors = $this->validator->validate($tax);
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

        $this->entityManager->persist($tax);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeTax($tax),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update a tax (only admins of admin tenants)
     */
    #[Route('/api/taxes/{id}', name: 'app_tax_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateTax(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $tax = $this->taxRepository->find($id);

        if (!$tax) {
            return new JsonResponse(
                ['error' => 'Tax not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        // Update rate if provided
        if (isset($data['rate'])) {
            $rate = $data['rate'] === null ? null : (float) $data['rate'];
            
            // Validate rate value
            if ($rate !== null && !in_array($rate, [0, 8, 19], true)) {
                return new JsonResponse(
                    ['error' => 'Tax rate must be null (exempted), 0, 8, or 19'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Check if another tax with this rate already exists (excluding current tax)
            $existingTax = $this->taxRepository->findByRate($rate);
            if ($existingTax && $existingTax->getId() !== $tax->getId()) {
                return new JsonResponse(
                    ['error' => 'Tax with this rate already exists'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $tax->setRate($rate === null ? null : (string) $rate);
        }

        // Update name if provided
        if (isset($data['name'])) {
            $tax->setName(trim($data['name']) ?: null);
        }

        // Validate entity
        $errors = $this->validator->validate($tax);
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

        return new JsonResponse($this->serializeTax($tax), Response::HTTP_OK);
    }

    /**
     * Delete a tax (only admins of admin tenants)
     */
    #[Route('/api/taxes/{id}', name: 'app_tax_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteTax(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);
        $this->ensureUserIsAdminOfAdminTenant($user);

        $tax = $this->taxRepository->find($id);

        if (!$tax) {
            return new JsonResponse(
                ['error' => 'Tax not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if tax is in use by any invoice items
        $itemsUsingTax = $this->invoiceItemRepository->createQueryBuilder('ii')
            ->andWhere('ii.tax = :tax')
            ->setParameter('tax', $tax)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (count($itemsUsingTax) > 0) {
            return new JsonResponse(
                ['error' => 'Cannot delete tax that is in use by invoice items'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->entityManager->remove($tax);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Tax deleted successfully'],
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
     * Ensure user is admin of admin tenant
     * - User must have ROLE_ADMIN in roles
     * - User's tenant must have isAdmin = true
     */
    private function ensureUserIsAdminOfAdminTenant(User $user): void
    {
        $this->ensureUserIsActive($user);

        // Check if user has admin role
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            throw $this->createAccessDeniedException('Only admin users can manage taxes');
        }

        // Check if user's tenant is an admin tenant
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            throw $this->createAccessDeniedException('Only admins of admin tenants can manage taxes');
        }
    }

    /**
     * Serialize tax to array
     */
    private function serializeTax(Tax $tax): array
    {
        return [
            'id' => $tax->getId(),
            'rate' => $tax->getRate(),
            'name' => $tax->getName(),
            'created_at' => $tax->getCreatedAt()?->format('c'),
            'updated_at' => $tax->getUpdatedAt()?->format('c'),
        ];
    }
}
