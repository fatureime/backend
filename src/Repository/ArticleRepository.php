<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Business;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Find all articles for a business
     *
     * @return Article[]
     */
    public function findByBusiness(Business $business): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.business = :business')
            ->setParameter('business', $business)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find article by ID and business (for access verification)
     */
    public function findByIdAndBusiness(int $id, Business $business): ?Article
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('a.business = :business')
            ->setParameter('id', $id)
            ->setParameter('business', $business)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all articles ordered by creation date (for admin tenants)
     *
     * @return Article[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
