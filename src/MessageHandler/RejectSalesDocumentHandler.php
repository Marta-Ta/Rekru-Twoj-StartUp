<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\SalesDocumentStatus;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RejectSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
    ) {
    }

    public function __invoke(RejectSalesDocument $command): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($command) {
            $document = $this->repository->getOrFail($command->documentId);
            $document->ensureIsDraft('rejected');

            $document->setStatus(SalesDocumentStatus::Rejected);
            $document->setRejectedBy($command->rejectedBy);
            $document->setRejectedAt(new DateTimeImmutable());

            return $document->getId();
        });
    }
}
