<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use App\Repository\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BusinessController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BusinessRepository $businessRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all businesses (admin tenants can see all, regular users see only their tenant's businesses)
     */
    #[Route('/api/businesses', name: 'app_businesses_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listBusinesses(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        // Admin tenants can see all businesses
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $businesses = $this->businessRepository->findAllForAdminTenant();
        } else {
            // Regular users can only see their tenant's businesses
            $businesses = $user->getTenant() 
                ? $this->businessRepository->findByTenant($user->getTenant())
                : [];
        }

        $data = array_map(function (Business $business) {
            return $this->serializeBusiness($business);
        }, $businesses);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single business by ID
     */
    #[Route('/api/businesses/{id}', name: 'app_business_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getBusiness(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($id);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        return new JsonResponse($this->serializeBusiness($business), Response::HTTP_OK);
    }

    /**
     * Create a new business
     */
    #[Route('/api/businesses', name: 'app_business_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createBusiness(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        // Users must have a tenant
        $tenant = $user->getTenant();
        if (!$tenant) {
            return new JsonResponse(
                ['error' => 'User must have a tenant to create a business'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['business_name']) || empty(trim($data['business_name']))) {
            return new JsonResponse(
                ['error' => 'Business name is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Create new business
        $business = new Business();
        $business->setBusinessName(trim($data['business_name']));
        $business->setCreatedBy($user);
        $business->setTenant($tenant);

        // Set optional fields
        if (isset($data['trade_name'])) {
            $business->setTradeName(trim($data['trade_name']) ?: null);
        }
        if (isset($data['business_type'])) {
            $business->setBusinessType(trim($data['business_type']) ?: null);
        }
        if (isset($data['unique_identifier_number'])) {
            $business->setUniqueIdentifierNumber(trim($data['unique_identifier_number']) ?: null);
        }
        if (isset($data['business_number'])) {
            $business->setBusinessNumber(trim($data['business_number']) ?: null);
        }
        if (isset($data['fiscal_number'])) {
            $business->setFiscalNumber(trim($data['fiscal_number']) ?: null);
        }
        if (isset($data['number_of_employees'])) {
            $business->setNumberOfEmployees((int) $data['number_of_employees'] ?: null);
        }
        if (isset($data['registration_date'])) {
            try {
                $registrationDate = new \DateTimeImmutable($data['registration_date']);
                $business->setRegistrationDate($registrationDate);
            } catch (\Exception $e) {
                return new JsonResponse(
                    ['error' => 'Invalid registration date format'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }
        if (isset($data['municipality'])) {
            $business->setMunicipality(trim($data['municipality']) ?: null);
        }
        if (isset($data['address'])) {
            $business->setAddress(trim($data['address']) ?: null);
        }
        if (isset($data['phone'])) {
            $business->setPhone(trim($data['phone']) ?: null);
        }
        if (isset($data['email'])) {
            $business->setEmail(trim($data['email']) ?: null);
        }
        if (isset($data['capital'])) {
            $business->setCapital($data['capital'] ? (string) $data['capital'] : null);
        }
        if (isset($data['arbk_status'])) {
            $business->setArbkStatus(trim($data['arbk_status']) ?: null);
        }

        // Validate entity
        $errors = $this->validator->validate($business);
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

        $this->entityManager->persist($business);
        $this->entityManager->flush();

        // If tenant has no issuer business, set this first business as issuer
        if (!$tenant->getIssuerBusiness()) {
            $tenant->setIssuerBusiness($business);
            $this->entityManager->flush();
        }

        return new JsonResponse(
            $this->serializeBusiness($business),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update a business
     */
    #[Route('/api/businesses/{id}', name: 'app_business_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateBusiness(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($id);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        $data = json_decode($request->getContent(), true);

        // Update fields if provided
        if (isset($data['business_name'])) {
            $business->setBusinessName(trim($data['business_name']));
        }
        if (isset($data['trade_name'])) {
            $business->setTradeName(trim($data['trade_name']) ?: null);
        }
        if (isset($data['business_type'])) {
            $business->setBusinessType(trim($data['business_type']) ?: null);
        }
        if (isset($data['unique_identifier_number'])) {
            $business->setUniqueIdentifierNumber(trim($data['unique_identifier_number']) ?: null);
        }
        if (isset($data['business_number'])) {
            $business->setBusinessNumber(trim($data['business_number']) ?: null);
        }
        if (isset($data['fiscal_number'])) {
            $business->setFiscalNumber(trim($data['fiscal_number']) ?: null);
        }
        if (isset($data['number_of_employees'])) {
            $business->setNumberOfEmployees((int) $data['number_of_employees'] ?: null);
        }
        if (isset($data['registration_date'])) {
            try {
                $registrationDate = new \DateTimeImmutable($data['registration_date']);
                $business->setRegistrationDate($registrationDate);
            } catch (\Exception $e) {
                return new JsonResponse(
                    ['error' => 'Invalid registration date format'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }
        if (isset($data['municipality'])) {
            $business->setMunicipality(trim($data['municipality']) ?: null);
        }
        if (isset($data['address'])) {
            $business->setAddress(trim($data['address']) ?: null);
        }
        if (isset($data['phone'])) {
            $business->setPhone(trim($data['phone']) ?: null);
        }
        if (isset($data['email'])) {
            $business->setEmail(trim($data['email']) ?: null);
        }
        if (isset($data['capital'])) {
            $business->setCapital($data['capital'] ? (string) $data['capital'] : null);
        }
        if (isset($data['arbk_status'])) {
            $business->setArbkStatus(trim($data['arbk_status']) ?: null);
        }

        // Validate entity
        $errors = $this->validator->validate($business);
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

        return new JsonResponse($this->serializeBusiness($business), Response::HTTP_OK);
    }

    /**
     * Delete a business
     */
    #[Route('/api/businesses/{id}', name: 'app_business_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteBusiness(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $business = $this->businessRepository->find($id);

        if (!$business) {
            return new JsonResponse(
                ['error' => 'Business not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this business
        $this->ensureUserCanAccessBusiness($user, $business);

        // Prevent deleting issuer business
        $tenant = $business->getTenant();
        if ($tenant && $tenant->getIssuerBusiness() && $tenant->getIssuerBusiness()->getId() === $business->getId()) {
            return new JsonResponse(
                ['error' => 'Cannot delete issuer business. This business is used for invoice creation.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->entityManager->remove($business);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Business deleted successfully'],
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
     * Serialize business to array
     */
    private function serializeBusiness(Business $business): array
    {
        $createdBy = $business->getCreatedBy();
        $tenant = $business->getTenant();

        return [
            'id' => $business->getId(),
            'business_name' => $business->getBusinessName(),
            'trade_name' => $business->getTradeName(),
            'business_type' => $business->getBusinessType(),
            'unique_identifier_number' => $business->getUniqueIdentifierNumber(),
            'business_number' => $business->getBusinessNumber(),
            'fiscal_number' => $business->getFiscalNumber(),
            'number_of_employees' => $business->getNumberOfEmployees(),
            'registration_date' => $business->getRegistrationDate()?->format('Y-m-d'),
            'municipality' => $business->getMunicipality(),
            'address' => $business->getAddress(),
            'phone' => $business->getPhone(),
            'email' => $business->getEmail(),
            'capital' => $business->getCapital(),
            'arbk_status' => $business->getArbkStatus(),
            'created_by_id' => $createdBy?->getId(),
            'tenant_id' => $tenant?->getId(),
            'created_at' => $business->getCreatedAt()?->format('c'),
            'updated_at' => $business->getUpdatedAt()?->format('c'),
            'created_by' => $createdBy ? [
                'id' => $createdBy->getId(),
                'email' => $createdBy->getEmail(),
            ] : null,
            'tenant' => $tenant ? [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
            ] : null,
        ];
    }
}
