<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private TenantRepository $tenantRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailService $emailService,
        private JWTTokenManagerInterface $jwtManager,
        private ValidatorInterface $validator
    ) {
    }

    #[Route('/api/register', name: 'app_register', methods: ['POST', 'OPTIONS'])]
    public function register(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse(
                ['error' => 'Email dhe fjalëkalimi janë të nevojshëm'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = trim($data['email']);
        $password = $data['password'];

        // Check if user already exists
        $existingUser = $this->userRepository->findOneByEmail($email);
        if ($existingUser) {
            return new JsonResponse(
                ['error' => 'Një përdorues me këtë email ekziston tashmë'],
                Response::HTTP_CONFLICT
            );
        }

        // Validate password strength
        if (strlen($password) < 8) {
            return new JsonResponse(
                ['error' => 'Fjalëkalimi duhet të jetë së paku 8 karaktere'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Handle tenant assignment
        $tenant = null;
        $isNewTenant = false;
        if (isset($data['tenant_id'])) {
            // User provided tenant_id - assign to existing tenant
            $tenant = $this->tenantRepository->find($data['tenant_id']);
            if (!$tenant) {
                return new JsonResponse(
                    ['error' => 'Tenant not found'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        } else {
            // Create a new tenant automatically for the new user
            $tenant = new Tenant();
            $tenant->setName('Tenant for ' . $email);
            $tenant->setHasPaid(false);
            $tenant->setIsAdmin(false);
            $isNewTenant = true;
            $this->entityManager->persist($tenant);
        }

        // Create new user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_USER']);
        $user->setEmailVerified(false);
        $user->setTenant($tenant);
        $user->setIsActive(true); // New users are active by default

        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($verificationToken);

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

        // Persist user (needed for business created_by)
        $this->entityManager->persist($user);

        // If a new tenant was created, automatically create a business for it and set as issuer
        // We flush in stages to avoid circular dependency cycle
        if ($isNewTenant) {
            // First flush: tenant and user (without issuerBusiness relationship)
            $this->entityManager->flush();
            
            // Now create the business (tenant exists, so no cycle)
            $business = new Business();
            $business->setBusinessName($tenant->getName()); // Default to tenant name
            $business->setCreatedBy($user);
            $business->setTenant($tenant);
            $this->entityManager->persist($business);
            
            // Flush business
            $this->entityManager->flush();
            
            // Now set the issuer business relationship (both entities exist, no cycle)
            $tenant->setIssuerBusiness($business);
            
            // Final flush to update tenant with issuer_business_id
            $this->entityManager->flush();
        } else {
            // If not a new tenant, just flush user
            $this->entityManager->flush();
        }

        // Send verification email
        try {
            $this->emailService->sendVerificationEmail($user);
        } catch (\Exception $e) {
            // Log error but don't fail registration
            // In production, you might want to handle this differently
        }

        return new JsonResponse(
            [
                'message' => 'Regjistrimi u krye me sukses. Ju lutem verifikoni email-in tuaj.',
                'email' => $user->getEmail()
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/api/verify-email', name: 'app_verify_email', methods: ['POST', 'OPTIONS'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['token'])) {
            return new JsonResponse(
                ['error' => 'Token i verifikimit është i nevojshëm'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $token = $data['token'];
        $user = $this->userRepository->findOneByEmailVerificationToken($token);

        if (!$user) {
            return new JsonResponse(
                ['error' => 'Token i verifikimit është i pavlefshëm ose ka skaduar'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($user->isEmailVerified()) {
            return new JsonResponse(
                ['message' => 'Email-i është verifikuar tashmë'],
                Response::HTTP_OK
            );
        }

        // Verify email
        $user->setEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $this->entityManager->flush();

        return new JsonResponse(
            [
                'message' => 'Email-i u verifikua me sukses',
                'email' => $user->getEmail()
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/api/login', name: 'app_login', methods: ['POST', 'OPTIONS'])]
    public function login(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse(
                ['error' => 'Email dhe fjalëkalimi janë të nevojshëm'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = trim($data['email']);
        $password = $data['password'];
        $rememberMe = isset($data['remember_me']) && $data['remember_me'] === true;

        // Find user
        $user = $this->userRepository->findOneByEmail($email);

        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(
                ['error' => 'Email ose fjalëkalim i gabuar'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // Check if email is verified
        if (!$user->isEmailVerified()) {
            return new JsonResponse(
                ['error' => 'Ju lutem verifikoni email-in tuaj para se të hyni'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Check if user is active
        if (!$user->isActive()) {
            return new JsonResponse(
                ['error' => 'Llogaria juaj është e çaktivizuar. Ju lutem kontaktoni administratorin.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Generate JWT token
        // For remember me, we'll use a longer TTL (7 days = 604800 seconds)
        // Note: The actual TTL is set in lexik_jwt_authentication.yaml, but we can override it
        $token = $this->jwtManager->create($user);

        // If remember me, generate and store remember me token
        if ($rememberMe) {
            $rememberMeToken = bin2hex(random_bytes(32));
            $user->setRememberMeToken($rememberMeToken);
            $this->entityManager->flush();
        }

        $tenant = $user->getTenant();
        $issuerBusiness = $tenant ? $tenant->getIssuerBusiness() : null;
        
        return new JsonResponse(
            [
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'roles' => $user->getRoles(),
                    'is_active' => $user->isActive(),
                    'tenant' => $tenant ? [
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
                    ] : null,
                ],
                'remember_me_token' => $rememberMe ? $user->getRememberMeToken() : null
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/api/user', name: 'app_get_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getCurrentUser(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(
                ['error' => 'Përdoruesi nuk u gjet'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $tenant = $user->getTenant();
        $issuerBusiness = $tenant ? $tenant->getIssuerBusiness() : null;

        return new JsonResponse(
            [
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
                    'issuer_business_id' => $issuerBusiness?->getId(),
                    'issuer_business' => $issuerBusiness ? [
                        'id' => $issuerBusiness->getId(),
                        'business_name' => $issuerBusiness->getBusinessName(),
                        'fiscal_number' => $issuerBusiness->getFiscalNumber(),
                    ] : null,
                ] : null,
            ],
            Response::HTTP_OK
        );
    }
}
