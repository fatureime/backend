<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TenantController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRepository $tenantRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all tenants (admin tenants can see all, regular users see only their own)
     */
    #[Route('/api/tenants', name: 'app_tenants_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listTenants(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        // Admin tenants can see all tenants
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $tenants = $this->tenantRepository->findBy([], ['createdAt' => 'DESC']);
        } else {
            // Regular users can only see their own tenant
            $tenants = $user->getTenant() ? [$user->getTenant()] : [];
        }

        $data = array_map(function (Tenant $tenant) {
            return $this->serializeTenant($tenant);
        }, $tenants);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single tenant by ID
     */
    #[Route('/api/tenants/{id}', name: 'app_tenant_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getTenant(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $tenant = $this->tenantRepository->find($id);

        if (!$tenant) {
            return new JsonResponse(
                ['error' => 'Tenant not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this tenant
        $this->ensureUserCanAccessTenant($user, $tenant);

        return new JsonResponse($this->serializeTenant($tenant), Response::HTTP_OK);
    }

    /**
     * Update a tenant
     */
    #[Route('/api/tenants/{id}', name: 'app_tenant_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateTenant(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $tenant = $this->tenantRepository->find($id);

        if (!$tenant) {
            return new JsonResponse(
                ['error' => 'Tenant not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this tenant
        $this->ensureUserCanAccessTenant($user, $tenant);

        $data = json_decode($request->getContent(), true);

        // Update fields if provided
        if (isset($data['name'])) {
            $tenant->setName($data['name']);
        }

        if (isset($data['has_paid'])) {
            $tenant->setHasPaid((bool) $data['has_paid']);
        }

        if (isset($data['is_admin'])) {
            // Only admin tenants can change is_admin status
            if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Only admin tenants can change admin status'],
                    Response::HTTP_FORBIDDEN
                );
            }
            $tenant->setIsAdmin((bool) $data['is_admin']);
        }

        // Prevent changing issuer_business_id (immutable after creation)
        if (isset($data['issuer_business_id'])) {
            return new JsonResponse(
                ['error' => 'Issuer business cannot be changed after creation'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validate entity
        $errors = $this->validator->validate($tenant);
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

        return new JsonResponse($this->serializeTenant($tenant), Response::HTTP_OK);
    }

    /**
     * Delete a tenant
     */
    #[Route('/api/tenants/{id}', name: 'app_tenant_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteTenant(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $user */
        $user = $this->getUser();
        
        $this->ensureUserIsActive($user);

        $tenant = $this->tenantRepository->find($id);

        if (!$tenant) {
            return new JsonResponse(
                ['error' => 'Tenant not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this tenant
        $this->ensureUserCanAccessTenant($user, $tenant);

        // Only admin tenants can delete tenants
        if (!$user->getTenant() || !$user->getTenant()->isAdminTenant()) {
            return new JsonResponse(
                ['error' => 'Only admin tenants can delete tenants'],
                Response::HTTP_FORBIDDEN
            );
        }

        $this->entityManager->remove($tenant);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Tenant deleted successfully'],
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
     * Ensure user can access tenant
     * - Admin tenants can access any tenant
     * - Regular users can only access their own tenant
     */
    private function ensureUserCanAccessTenant(User $user, Tenant $tenant): void
    {
        $this->ensureUserIsActive($user);

        // Admin tenants can access any tenant
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            return;
        }

        // Regular users can only access their own tenant
        if ($user->getTenant() !== $tenant) {
            throw $this->createAccessDeniedException('You do not have access to this tenant');
        }
    }

    /**
     * Serialize tenant to array
     */
    private function serializeTenant(Tenant $tenant): array
    {
        $users = [];
        foreach ($tenant->getUsers() as $user) {
            $users[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'is_active' => $user->isActive(),
                'email_verified' => $user->isEmailVerified(),
            ];
        }

        $issuerBusiness = $tenant->getIssuerBusiness();

        return [
            'id' => $tenant->getId(),
            'name' => $tenant->getName(),
            'has_paid' => $tenant->isHasPaid(),
            'is_admin' => $tenant->isAdmin(),
            'issuer_business_id' => $issuerBusiness?->getId(),
            'issuer_business' => $issuerBusiness ? [
                'id' => $issuerBusiness->getId(),
                'business_name' => $issuerBusiness->getBusinessName(),
                'fiscal_number' => $issuerBusiness->getFiscalNumber(),
            ] : null,
            'created_at' => $tenant->getCreatedAt()?->format('c'),
            'updated_at' => $tenant->getUpdatedAt()?->format('c'),
            'users' => $users,
        ];
    }
}
