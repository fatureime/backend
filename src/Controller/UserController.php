<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private TenantRepository $tenantRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailService $emailService,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * List users
     * - Admin users see users from their tenant
     * - Admin tenant users see all users
     */
    #[Route('/api/users', name: 'app_users_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listUsers(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        // Check if user can manage all tenants (admin tenant)
        $canManageAllTenants = $currentUser->getTenant() && $currentUser->getTenant()->isAdminTenant();

        // Optional tenant filter
        $tenantId = $request->query->getInt('tenant_id', 0);
        
        if ($canManageAllTenants) {
            // Admin tenant users can see all users
            if ($tenantId > 0) {
                $tenant = $this->tenantRepository->find($tenantId);
                if (!$tenant) {
                    return new JsonResponse(
                        ['error' => 'Tenant not found'],
                        Response::HTTP_NOT_FOUND
                    );
                }
                $users = $this->userRepository->findByTenant($tenant);
            } else {
                $users = $this->userRepository->findAll();
            }
        } else {
            // Regular admin users can only see users from their tenant
            $tenant = $currentUser->getTenant();
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'User has no tenant assigned'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $users = $this->userRepository->findByTenant($tenant);
        }

        $data = array_map(function (User $user) {
            return $this->serializeUser($user);
        }, $users);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single user by ID
     */
    #[Route('/api/users/{id}', name: 'app_user_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getUserById(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        $user = $this->userRepository->find($id);

        if (!$user) {
            return new JsonResponse(
                ['error' => 'User not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can access this user
        $this->ensureUserCanManageUser($currentUser, $user);

        return new JsonResponse($this->serializeUser($user), Response::HTTP_OK);
    }

    /**
     * Create a new user directly (for admins)
     */
    #[Route('/api/users', name: 'app_user_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createUser(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse(
                ['error' => 'Email and password are required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = trim($data['email']);
        $password = $data['password'];

        // Check if user already exists
        $existingUser = $this->userRepository->findOneByEmail($email);
        if ($existingUser) {
            return new JsonResponse(
                ['error' => 'A user with this email already exists'],
                Response::HTTP_CONFLICT
            );
        }

        // Validate password strength
        if (strlen($password) < 8) {
            return new JsonResponse(
                ['error' => 'Password must be at least 8 characters'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Determine tenant
        $tenant = null;
        if (isset($data['tenant_id'])) {
            $tenant = $this->tenantRepository->find($data['tenant_id']);
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'Tenant not found'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            // Check if current user can assign to this tenant
            if (!$this->canUserManageTenant($currentUser, $tenant)) {
                return new JsonResponse(
                    ['error' => 'You do not have permission to create users for this tenant'],
                    Response::HTTP_FORBIDDEN
                );
            }
        } else {
            // Default to current user's tenant
            $tenant = $currentUser->getTenant();
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'User has no tenant assigned'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Create new user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles($data['roles'] ?? ['ROLE_USER']);
        $user->setEmailVerified($data['email_verified'] ?? false);
        $user->setTenant($tenant);
        $user->setIsActive($data['is_active'] ?? true);

        // Generate verification token if email not verified
        if (!$user->isEmailVerified()) {
            $verificationToken = bin2hex(random_bytes(32));
            $user->setEmailVerificationToken($verificationToken);
        }

        // Validate entity
        $errors = $this->validator->validate($user);
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

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeUser($user),
            Response::HTTP_CREATED
        );
    }

    /**
     * Invite a new user via email
     */
    #[Route('/api/users/invite', name: 'app_user_invite', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function inviteUser(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'])) {
            return new JsonResponse(
                ['error' => 'Email is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = trim($data['email']);

        // Check if user already exists
        $existingUser = $this->userRepository->findOneByEmail($email);
        if ($existingUser) {
            return new JsonResponse(
                ['error' => 'A user with this email already exists'],
                Response::HTTP_CONFLICT
            );
        }

        // Determine tenant
        $tenant = null;
        if (isset($data['tenant_id'])) {
            $tenant = $this->tenantRepository->find($data['tenant_id']);
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'Tenant not found'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            // Check if current user can assign to this tenant
            if (!$this->canUserManageTenant($currentUser, $tenant)) {
                return new JsonResponse(
                    ['error' => 'You do not have permission to invite users for this tenant'],
                    Response::HTTP_FORBIDDEN
                );
            }
        } else {
            // Default to current user's tenant
            $tenant = $currentUser->getTenant();
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'User has no tenant assigned'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Create new user with temporary password (will be set via invitation)
        $user = new User();
        $user->setEmail($email);
        // Set a temporary random password (user will set their own via invitation link)
        $tempPassword = bin2hex(random_bytes(16));
        $user->setPassword($this->passwordHasher->hashPassword($user, $tempPassword));
        $user->setRoles($data['roles'] ?? ['ROLE_USER']);
        $user->setEmailVerified(false);
        $user->setTenant($tenant);
        $user->setIsActive(true);

        // Generate invitation token
        $invitationToken = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($invitationToken);

        // Validate entity
        $errors = $this->validator->validate($user);
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

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Send invitation email
        try {
            $this->emailService->sendInvitationEmail($user, $invitationToken);
        } catch (\Exception $e) {
            // Log error but don't fail invitation
            // In production, you might want to handle this differently
        }

        return new JsonResponse(
            [
                'message' => 'Invitation sent successfully',
                'user' => $this->serializeUser($user)
            ],
            Response::HTTP_CREATED
        );
    }

    /**
     * Update a user
     */
    #[Route('/api/users/{id}', name: 'app_user_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        $user = $this->userRepository->find($id);

        if (!$user) {
            return new JsonResponse(
                ['error' => 'User not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can manage this user
        $this->ensureUserCanManageUser($currentUser, $user);

        $data = json_decode($request->getContent(), true);

        // Update email if provided
        if (isset($data['email'])) {
            $email = trim($data['email']);
            // Check if email is already taken by another user
            $existingUser = $this->userRepository->findOneByEmail($email);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return new JsonResponse(
                    ['error' => 'A user with this email already exists'],
                    Response::HTTP_CONFLICT
                );
            }
            $user->setEmail($email);
        }

        // Update password if provided
        if (isset($data['password'])) {
            if (strlen($data['password']) < 8) {
                return new JsonResponse(
                    ['error' => 'Password must be at least 8 characters'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        }

        // Update roles if provided
        if (isset($data['roles'])) {
            $user->setRoles($data['roles']);
        }

        // Update is_active if provided
        if (isset($data['is_active']) !== null) {
            $user->setIsActive((bool) $data['is_active']);
        }

        // Update tenant if provided (only admin tenant users can change tenant)
        if (isset($data['tenant_id'])) {
            $newTenant = $this->tenantRepository->find($data['tenant_id']);
            if (!$newTenant) {
                return new JsonResponse(
                    ['error' => 'Tenant not found'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            // Only admin tenant users can change tenant
            if (!$currentUser->getTenant() || !$currentUser->getTenant()->isAdminTenant()) {
                return new JsonResponse(
                    ['error' => 'Only admin tenant users can change user tenant'],
                    Response::HTTP_FORBIDDEN
                );
            }
            $user->setTenant($newTenant);
        }

        // Validate entity
        $errors = $this->validator->validate($user);
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

        return new JsonResponse($this->serializeUser($user), Response::HTTP_OK);
    }

    /**
     * Delete a user
     */
    #[Route('/api/users/{id}', name: 'app_user_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteUser(int $id, Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        $user = $this->userRepository->find($id);

        if (!$user) {
            return new JsonResponse(
                ['error' => 'User not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Check if user can manage this user
        $this->ensureUserCanManageUser($currentUser, $user);

        // Prevent self-deletion
        if ($user->getId() === $currentUser->getId()) {
            return new JsonResponse(
                ['error' => 'You cannot delete your own account'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'User deleted successfully'],
            Response::HTTP_OK
        );
    }

    /**
     * Accept invitation and set password
     */
    #[Route('/api/users/accept-invitation', name: 'app_user_accept_invitation', methods: ['POST', 'OPTIONS'])]
    public function acceptInvitation(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['token']) || !isset($data['password'])) {
            return new JsonResponse(
                ['error' => 'Token and password are required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $token = $data['token'];
        $password = $data['password'];

        // Validate password strength
        if (strlen($password) < 8) {
            return new JsonResponse(
                ['error' => 'Password must be at least 8 characters'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $this->userRepository->findOneByEmailVerificationToken($token);

        if (!$user) {
            return new JsonResponse(
                ['error' => 'Invalid or expired invitation token'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Set password and verify email
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setEmailVerified(true);
        $user->setEmailVerificationToken(null);

        $this->entityManager->flush();

        return new JsonResponse(
            [
                'message' => 'Invitation accepted successfully. You can now log in.',
                'user' => $this->serializeUser($user)
            ],
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
     * Ensure user has admin role
     */
    private function ensureUserIsAdmin(User $user): void
    {
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            throw $this->createAccessDeniedException('Only admin users can manage other users');
        }
    }

    /**
     * Ensure user can manage another user
     * - Admin tenant users can manage any user
     * - Regular admin users can only manage users from their tenant
     */
    private function ensureUserCanManageUser(User $currentUser, User $targetUser): void
    {
        $this->ensureUserIsActive($currentUser);
        $this->ensureUserIsAdmin($currentUser);

        // Admin tenant users can manage any user
        if ($currentUser->getTenant() && $currentUser->getTenant()->isAdminTenant()) {
            return;
        }

        // Regular admin users can only manage users from their tenant
        if ($currentUser->getTenant() !== $targetUser->getTenant()) {
            throw $this->createAccessDeniedException('You do not have permission to manage this user');
        }
    }

    /**
     * Check if user can manage a tenant
     */
    private function canUserManageTenant(User $user, Tenant $tenant): bool
    {
        // Admin tenant users can manage any tenant
        if ($user->getTenant() && $user->getTenant()->isAdminTenant()) {
            return true;
        }

        // Regular admin users can only manage their own tenant
        return $user->getTenant() === $tenant;
    }

    /**
     * Serialize user to array
     */
    private function serializeUser(User $user): array
    {
        $tenant = $user->getTenant();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'email_verified' => $user->isEmailVerified(),
            'is_active' => $user->isActive(),
            'tenant' => $tenant ? [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
                'has_paid' => $tenant->isHasPaid(),
                'is_admin' => $tenant->isAdmin(),
            ] : null,
            'created_at' => $user->getCreatedAt()?->format('c'),
            'updated_at' => $user->getUpdatedAt()?->format('c'),
        ];
    }
}
