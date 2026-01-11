<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Business>
 */
class BusinessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Business::class);
    }

    /**
     * Find all businesses for a tenant
     *
     * @return Business[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all businesses for user's tenant
     *
     * @return Business[]
     */
    public function findByUser(User $user): array
    {
        $tenant = $user->getTenant();
        if (!$tenant) {
            return [];
        }

        return $this->findByTenant($tenant);
    }

    /**
     * Find all businesses (for admin tenants)
     *
     * @return Business[]
     */
    public function findAllForAdminTenant(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find business by ID and tenant (for access verification)
     */
    public function findByIdAndTenant(int $id, Tenant $tenant): ?Business
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.id = :id')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find businesses created by a user
     *
     * @return Business[]
     */
    public function findByCreatedBy(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find issuer business for a tenant
     */
    public function findIssuerBusiness(Tenant $tenant): ?Business
    {
        return $tenant->getIssuerBusiness();
    }

    /**
     * Find receiver businesses for a tenant (all businesses except issuer)
     *
     * @return Business[]
     */
    public function findReceiverBusinesses(Tenant $tenant): array
    {
        $issuerBusiness = $tenant->getIssuerBusiness();
        
        if (!$issuerBusiness) {
            // If no issuer, return all businesses
            return $this->findByTenant($tenant);
        }

        return $this->createQueryBuilder('b')
            ->andWhere('b.tenant = :tenant')
            ->andWhere('b.id != :issuerId')
            ->setParameter('tenant', $tenant)
            ->setParameter('issuerId', $issuerBusiness->getId())
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
