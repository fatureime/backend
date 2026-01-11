<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Business;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ArticleController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articleRepository,
        private BusinessRepository $businessRepository,
        private ValidatorInterface $validator
    ) {
    }

    /**
     * Get all articles for a business
     */
    #[Route('/api/businesses/{businessId}/articles', name: 'app_articles_list', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function listArticles(int $businessId, Request $request): JsonResponse
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

        $articles = $this->articleRepository->findByBusiness($business);

        $data = array_map(function (Article $article) {
            return $this->serializeArticle($article);
        }, $articles);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * Get a single article by ID
     */
    #[Route('/api/businesses/{businessId}/articles/{id}', name: 'app_article_get', methods: ['GET', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function getArticle(int $businessId, int $id, Request $request): JsonResponse
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

        $article = $this->articleRepository->findByIdAndBusiness($id, $business);

        if (!$article) {
            return new JsonResponse(
                ['error' => 'Article not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->serializeArticle($article), Response::HTTP_OK);
    }

    /**
     * Create a new article
     */
    #[Route('/api/businesses/{businessId}/articles', name: 'app_article_create', methods: ['POST', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function createArticle(int $businessId, Request $request): JsonResponse
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

        $data = json_decode($request->getContent(), true);

        if (!isset($data['name']) || empty(trim($data['name']))) {
            return new JsonResponse(
                ['error' => 'Article name is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!isset($data['unit_price']) || !is_numeric($data['unit_price'])) {
            return new JsonResponse(
                ['error' => 'Unit price is required and must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Create new article
        $article = new Article();
        $article->setBusiness($business);
        $article->setName(trim($data['name']));
        $article->setUnitPrice((string) $data['unit_price']);

        // Set optional fields
        if (isset($data['description'])) {
            $article->setDescription(trim($data['description']) ?: null);
        }
        if (isset($data['unit'])) {
            $article->setUnit(trim($data['unit']) ?: null);
        }

        // Validate entity
        $errors = $this->validator->validate($article);
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

        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeArticle($article),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update an article
     */
    #[Route('/api/businesses/{businessId}/articles/{id}', name: 'app_article_update', methods: ['PUT', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function updateArticle(int $businessId, int $id, Request $request): JsonResponse
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

        $article = $this->articleRepository->findByIdAndBusiness($id, $business);

        if (!$article) {
            return new JsonResponse(
                ['error' => 'Article not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        // Update fields if provided
        if (isset($data['name'])) {
            $article->setName(trim($data['name']));
        }
        if (isset($data['description'])) {
            $article->setDescription(trim($data['description']) ?: null);
        }
        if (isset($data['unit_price'])) {
            if (!is_numeric($data['unit_price'])) {
                return new JsonResponse(
                    ['error' => 'Unit price must be a number'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $article->setUnitPrice((string) $data['unit_price']);
        }
        if (isset($data['unit'])) {
            $article->setUnit(trim($data['unit']) ?: null);
        }

        // Validate entity
        $errors = $this->validator->validate($article);
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

        return new JsonResponse($this->serializeArticle($article), Response::HTTP_OK);
    }

    /**
     * Delete an article
     */
    #[Route('/api/businesses/{businessId}/articles/{id}', name: 'app_article_delete', methods: ['DELETE', 'OPTIONS'])]
    #[IsGranted('ROLE_USER')]
    public function deleteArticle(int $businessId, int $id, Request $request): JsonResponse
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

        $article = $this->articleRepository->findByIdAndBusiness($id, $business);

        if (!$article) {
            return new JsonResponse(
                ['error' => 'Article not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->entityManager->remove($article);
        $this->entityManager->flush();

        return new JsonResponse(
            ['message' => 'Article deleted successfully'],
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
     * Serialize article to array
     */
    private function serializeArticle(Article $article): array
    {
        $business = $article->getBusiness();

        return [
            'id' => $article->getId(),
            'name' => $article->getName(),
            'description' => $article->getDescription(),
            'unit_price' => $article->getUnitPrice(),
            'unit' => $article->getUnit(),
            'business_id' => $business?->getId(),
            'created_at' => $article->getCreatedAt()?->format('c'),
            'updated_at' => $article->getUpdatedAt()?->format('c'),
            'business' => $business ? [
                'id' => $business->getId(),
                'business_name' => $business->getBusinessName(),
            ] : null,
        ];
    }
}
