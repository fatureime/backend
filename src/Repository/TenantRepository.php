<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Find tenant by user
     */
    public function findOneByUser(User $user): ?Tenant
    {
        return $user->getTenant();
    }

    /**
     * Find all admin tenants
     *
     * @return Tenant[]
     */
    public function findAdminTenants(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isAdmin = :isAdmin')
            ->setParameter('isAdmin', true)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all paid tenants
     *
     * @return Tenant[]
     */
    public function findPaidTenants(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.hasPaid = :hasPaid')
            ->setParameter('hasPaid', true)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tenant by issuer business
     */
    public function findOneByIssuerBusiness(Business $business): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.issuerBusiness = :business')
            ->setParameter('business', $business)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
