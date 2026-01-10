<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Find a user by email
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a user by email verification token
     */
    public function findOneByEmailVerificationToken(string $token): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.emailVerificationToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a user by remember me token
     */
    public function findOneByRememberMeToken(string $token): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.rememberMeToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all users by tenant
     *
     * @return User[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active users by tenant
     *
     * @return User[]
     */
    public function findActiveUsersByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('u.isActive = :isActive')
            ->setParameter('tenant', $tenant)
            ->setParameter('isActive', true)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active users
     *
     * @return User[]
     */
    public function findActiveUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
