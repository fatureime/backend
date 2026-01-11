<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceItem>
 */
class InvoiceItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceItem::class);
    }

    /**
     * Find all items for an invoice (ordered by sortOrder)
     *
     * @return InvoiceItem[]
     */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('ii')
            ->andWhere('ii.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('ii.sortOrder', 'ASC')
            ->addOrderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find item by ID and invoice (for access verification)
     */
    public function findByIdAndInvoice(int $id, Invoice $invoice): ?InvoiceItem
    {
        return $this->createQueryBuilder('ii')
            ->andWhere('ii.id = :id')
            ->andWhere('ii.invoice = :invoice')
            ->setParameter('id', $id)
            ->setParameter('invoice', $invoice)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
