<?php

namespace App\Repository;

use App\Entity\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceStatus>
 */
class InvoiceStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceStatus::class);
    }

    /**
     * Find invoice status by code
     *
     * @param string $code
     * @return InvoiceStatus|null
     */
    public function findByCode(string $code): ?InvoiceStatus
    {
        return $this->createQueryBuilder('ist')
            ->andWhere('ist.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all invoice statuses ordered by code
     *
     * @return InvoiceStatus[]
     */
    public function findAllOrderedByCode(): array
    {
        return $this->createQueryBuilder('ist')
            ->orderBy('ist.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
