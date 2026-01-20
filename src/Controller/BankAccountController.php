<?php

namespace App\Controller;

use App\Entity\BankAccount;
use App\Entity\Business;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BankAccountRepository;
use App\Repository\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BankAccountController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BankAccountRepository $bankAccountRepository,
        private BusinessRepository $businessRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all bank accounts for a business
     * Admin tenants can see all bank accounts across all businesses
     */
    #[Route('/api/businesses/{businessId}/bank-accounts', name: 'app_bank_accounts_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listBankAccounts(int $businessId, Request $request): JsonResponse
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

        // Admin tenants can see all bank accounts across all businesses
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $bankAccounts = $this->bankAccountRepository->findAllOrdered();
        } else {
            $bankAccounts = $this->bankAccountRepository->findByBusiness($business);
        }

        $data = array_map(function (BankAccount $bankAccount) {
            return $this->serializeBankAccount($bankAccount);
        }, $bankAccounts);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single bank account by ID
     * Admin tenants can access any bank account regardless of business
     */
    #[Route('/api/businesses/{businessId}/bank-accounts/{id}', name: 'app_bank_account_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getBankAccount(int $businessId, int $id, Request $request): JsonResponse
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

        // Admin tenants can access any bank account, regular users only bank accounts from their business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $bankAccount = $this->bankAccountRepository->find($id);
        } else {
            $bankAccount = $this->bankAccountRepository->findByIdAndBusiness($id, $business);
        }

        if (!$bankAccount) {
            return new JsonResponse(
                ['error' => 'Bank account not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->serializeBankAccount($bankAccount), Response::HTTP_OK);
    }

    /**
     * Create a new bank account
     */
    #[Route('/api/businesses/{businessId}/bank-accounts', name: 'app_bank_account_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createBankAccount(int $businessId, Request $request): JsonResponse
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

        // Check if user can write to this tenant (non-admin users from admin tenants can only write to their own tenant)
        $this->ensureUserCanWriteToTenant($user, $business->getTenant());

        $data = json_decode($request->getContent(), true);

        // Validate that bank_account_number is provided
        if (!isset($data['bank_account_number']) || empty(trim($data['bank_account_number']))) {
            return new JsonResponse(
                ['error' => 'Bank account number is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Create new bank account
        $bankAccount = new BankAccount();
        $bankAccount->setBusiness($business);
        $bankAccount->setBankAccountNumber(trim($data['bank_account_number']));

        if (isset($data['swift']) && !empty(trim($data['swift']))) {
            $bankAccount->setSwift($data['swift']);
        }
        if (isset($data['iban']) && !empty(trim($data['iban']))) {
            $bankAccount->setIban($data['iban']);
        }
        if (isset($data['bank_name']) && !empty(trim($data['bank_name']))) {
            $bankAccount->setBankName($data['bank_name']);
        }

        // Validate entity
        $errors = $this->validator->validate($bankAccount);
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

        $this->entityManager->persist($bankAccount);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeBankAccount($bankAccount),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update a bank account
     */
    #[Route('/api/businesses/{businessId}/bank-accounts/{id}', name: 'app_bank_account_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateBankAccount(int $businessId, int $id, Request $request): JsonResponse
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

        // Admin tenants can access any bank account, regular users only bank accounts from their business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $bankAccount = $this->bankAccountRepository->find($id);
        } else {
            $bankAccount = $this->bankAccountRepository->findByIdAndBusiness($id, $business);
        }

        if (!$bankAccount) {
            return new JsonResponse(
                ['error' => 'Bank account not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can write to this tenant (non-admin users from admin tenants can only write to their own tenant)
        $this->ensureUserCanWriteToTenant($user, $bankAccount->getBusiness()->getTenant());

        $data = json_decode($request->getContent(), true);

        // Validate that bank_account_number is provided
        if (isset($data['bank_account_number'])) {
            if (empty(trim($data['bank_account_number']))) {
                return new JsonResponse(
                    ['error' => 'Bank account number is required'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $bankAccount->setBankAccountNumber(trim($data['bank_account_number']));
        } else {
            // If not provided in update, ensure existing value is not empty
            if (empty($bankAccount->getBankAccountNumber())) {
                return new JsonResponse(
                    ['error' => 'Bank account number is required'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Update optional fields if provided
        if (isset($data['swift'])) {
            $bankAccount->setSwift($data['swift']);
        }
        if (isset($data['iban'])) {
            $bankAccount->setIban($data['iban']);
        }
        if (isset($data['bank_name'])) {
            $bankAccount->setBankName($data['bank_name']);
        }

        // Validate entity
        $errors = $this->validator->validate($bankAccount);
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

        return new JsonResponse($this->serializeBankAccount($bankAccount), Response::HTTP_OK);
    }

    /**
     * Delete a bank account
     */
    #[Route('/api/businesses/{businessId}/bank-accounts/{id}', name: 'app_bank_account_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteBankAccount(int $businessId, int $id, Request $request): JsonResponse
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

        // Admin tenants can access any bank account, regular users only bank accounts from their business
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            $bankAccount = $this->bankAccountRepository->find($id);
        } else {
            $bankAccount = $this->bankAccountRepository->findByIdAndBusiness($id, $business);
        }

        if (!$bankAccount) {
            return new JsonResponse(
                ['error' => 'Bank account not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can write to this tenant (non-admin users from admin tenants can only write to their own tenant)
        $this->ensureUserCanWriteToTenant($user, $bankAccount->getBusiness()->getTenant());

        $this->entityManager->remove($bankAccount);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Bank account deleted successfully'],
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
     * Serialize bank account to array
     */
    private function serializeBankAccount(BankAccount $bankAccount): array
    {
        $business = $bankAccount->getBusiness();

        return [
            'id' => $bankAccount->getId(),
            'swift' => $bankAccount->getSwift(),
            'iban' => $bankAccount->getIban(),
            'bank_account_number' => $bankAccount->getBankAccountNumber(),
            'bank_name' => $bankAccount->getBankName(),
            'business_id' => $business?->getId(),
            'created_at' => $bankAccount->getCreatedAt()?->format('c'),
            'updated_at' => $bankAccount->getUpdatedAt()?->format('c'),
            'business' => $business ? [
                'id' => $business->getId(),
                'business_name' => $business->getBusinessName(),
            ] : null,
        ];
    }
}
