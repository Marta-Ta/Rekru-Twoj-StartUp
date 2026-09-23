<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Message\Command\ApproveSalesDocument;
use App\Notification\NotifierPort;
use App\Repository\SalesDocumentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(bus: 'command.bus')]
final class ApproveSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
        private readonly NotifierPort $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApproveSalesDocument $command): int
    {
        $approvedId = $this->entityManager->wrapInTransaction(function () use ($command) {
            $document = $this->repository->getOrFail($command->documentId);
            $document->ensureIsDraft('approved');

            $document->setStatus(SalesDocumentStatus::Approved);
            $document->setApprovedBy($command->approvedBy);
            $document->setApprovedAt(new DateTimeImmutable());
            $document->setSellerSnapshot($this->buildSellerSnapshot($document));

            $approvedId = $document->getId();

            if ($document->getType() === SalesDocumentType::Quote) {
                $order = new SalesDocument();
                $order->setContractorId($document->getContractorId());
                $order->setCreatedBy($command->approvedBy);
                $order->setType(SalesDocumentType::Order);
                $order->setStatus(SalesDocumentStatus::Approved);
                $order->setApprovedBy($command->approvedBy);
                $order->setApprovedAt(new DateTimeImmutable());
                $order->setParentQuoteId($document->getId());
                $order->setSellerSnapshot($document->getSellerSnapshot());
                $this->entityManager->persist($order);
                $this->entityManager->flush();
                $approvedId = $order->getId();
            }

            return $approvedId;
        });

        $approvedDocument = $this->repository->getOrFail($approvedId);

        $message = "Document #{$approvedDocument->getId()} has been approved";
        $this->notifySafely($approvedDocument->getCreatedBy(), $message);
        $this->notifySafely($approvedDocument->getContractorId(), $message);

        return $approvedId;
    }

    private function notifySafely(int $userId, string $message): void
    {
        try {
            $this->notifier->notify($userId, $message);
        } catch (Throwable $e) {
            $this->logger->error('Failed to notify user {userId} about sales document approval: {message}', [
                'userId' => $userId,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSellerSnapshot(SalesDocument $document): array
    {
        return [
            'contractor_id' => $document->getContractorId(),
            'snapshot_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
