<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Business;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankAccount>
 */
class BankAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankAccount::class);
    }

    /**
     * Find all bank accounts for a business
     *
     * @return BankAccount[]
     */
    public function findByBusiness(Business $business): array
    {
        return $this->createQueryBuilder('ba')
            ->andWhere('ba.business = :business')
            ->setParameter('business', $business)
            ->orderBy('ba.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find bank account by ID and business (for access verification)
     */
    public function findByIdAndBusiness(int $id, Business $business): ?BankAccount
    {
        return $this->createQueryBuilder('ba')
            ->andWhere('ba.id = :id')
            ->andWhere('ba.business = :business')
            ->setParameter('id', $id)
            ->setParameter('business', $business)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
