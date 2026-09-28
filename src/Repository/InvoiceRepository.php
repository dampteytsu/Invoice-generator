<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invoice> */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** Highest invoice number of a company that starts with the given prefix, e.g. "INV-2026-". */
    public function findLastNumberWithPrefix(Company $company, string $prefix): ?string
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.invoiceNumber')
            ->where('i.company = :company')
            ->andWhere('i.invoiceNumber LIKE :p')
            ->setParameter('company', $company)
            ->setParameter('p', addcslashes($prefix, '%_').'%')
            ->orderBy('i.invoiceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['invoiceNumber'] ?? null;
    }

    /**
     * @return array{count:int, billed:float, outstanding:float, paid:float, overdue:int}
     */
    public function getStats(?Company $company): array
    {
        $cancelled = Invoice::STATUS_CANCELLED;
        $paid = Invoice::STATUS_PAID;

        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id) AS cnt')
            ->addSelect('COALESCE(SUM(CASE WHEN i.status != :cancelled THEN i.total ELSE 0 END), 0) AS billed')
            ->addSelect('COALESCE(SUM(CASE WHEN i.status = :paid THEN i.total ELSE 0 END), 0) AS paidSum')
            ->addSelect('COALESCE(SUM(CASE WHEN i.status NOT IN (:closed) THEN i.total ELSE 0 END), 0) AS outstanding')
            ->addSelect('COALESCE(SUM(CASE WHEN i.status NOT IN (:closed) AND i.dueDate < :today THEN 1 ELSE 0 END), 0) AS overdue')
            ->setParameter('cancelled', $cancelled)
            ->setParameter('paid', $paid)
            ->setParameter('closed', [$paid, $cancelled])
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');

        if ($company) {
            $qb->where('i.company = :company')->setParameter('company', $company);
        }

        $r = $qb->getQuery()->getSingleResult();

        return [
            'count' => (int) $r['cnt'],
            'billed' => (float) $r['billed'],
            'paid' => (float) $r['paidSum'],
            'outstanding' => (float) $r['outstanding'],
            'overdue' => (int) $r['overdue'],
        ];
    }

    /** @return Invoice[] */
    public function findRecent(?Company $company, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('i')
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);

        if ($company) {
            $qb->where('i.company = :company')->setParameter('company', $company);
        }

        return $qb->getQuery()->getResult();
    }
}
