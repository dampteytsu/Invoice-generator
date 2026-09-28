<?php

namespace App\Repository;

use App\Entity\BankDetailsChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankDetailsChange> */
class BankDetailsChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankDetailsChange::class);
    }
}
