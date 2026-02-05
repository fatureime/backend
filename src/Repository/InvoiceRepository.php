<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Invoice;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Find all invoices issued by a business
     *
     * @return Invoice[]
     */
    public function findByIssuerBusiness(Business $business): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.issuer = :business')
            ->setParameter('business', $business)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find invoice by ID and issuer business (for access verification)
     */
    public function findByIdAndIssuerBusiness(int $id, Business $business): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.id = :id')
            ->andWhere('i.issuer = :business')
            ->setParameter('id', $id)
            ->setParameter('business', $business)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all invoices received by a business
     *
     * @return Invoice[]
     */
    public function findByReceiverBusiness(Business $business): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.receiver = :business')
            ->setParameter('business', $business)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all invoices issued by businesses belonging to a tenant
     *
     * @return Invoice[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.issuer', 'b')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Generate next sequential invoice number for an issuer business
     * Format: INV-{BUSINESS_ID}-{SEQUENTIAL_NUMBER}
     * 
     * @param Business $issuer
     * @return string
     */
    public function generateNextInvoiceNumber(Business $issuer): string
    {
        // Find the highest invoice number for this issuer
        $lastInvoice = $this->createQueryBuilder('i')
            ->andWhere('i.issuer = :issuer')
            ->setParameter('issuer', $issuer)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastInvoice && $lastInvoice->getInvoiceNumber()) {
            // Extract the sequence number from the last invoice number
            $lastNumber = $lastInvoice->getInvoiceNumber();
            $pattern = '/^INV-' . $issuer->getId() . '-(\d+)$/';
            
            if (preg_match($pattern, $lastNumber, $matches)) {
                $nextSequence = (int) $matches[1] + 1;
            } else {
                // If format doesn't match, start from 1
                $nextSequence = 1;
            }
        } else {
            // First invoice for this issuer
            $nextSequence = 1;
        }

        return sprintf('INV-%d-%d', $issuer->getId(), $nextSequence);
    }
}
