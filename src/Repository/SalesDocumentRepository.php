<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesDocument;
use App\Exception\SalesDocumentNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SalesDocument>
 */
class SalesDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesDocument::class);
    }

    public function getOrFail(int $id): SalesDocument
    {
        $document = $this->find($id);

        if ($document === null) {
            throw SalesDocumentNotFoundException::withId($id);
        }

        return $document;
    }
}
