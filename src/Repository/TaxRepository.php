<?php

namespace App\Repository;

use App\Entity\Tax;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tax>
 */
class TaxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tax::class);
    }

    /**
     * Find all tax rates
     *
     * @return Tax[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.rate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tax by rate (null for exempted)
     *
     * @param float|null $rate
     * @return Tax|null
     */
    public function findByRate(?float $rate): ?Tax
    {
        $qb = $this->createQueryBuilder('t');

        if ($rate === null) {
            $qb->andWhere('t.rate IS NULL');
        } else {
            $qb->andWhere('t.rate = :rate')
               ->setParameter('rate', (string) $rate);
        }

        return $qb->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find exempted tax (rate is null)
     *
     * @return Tax|null
     */
    public function findExempted(): ?Tax
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.rate IS NULL')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
