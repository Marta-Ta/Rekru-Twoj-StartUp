<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvalidSalesDocumentStateException;
use App\Exception\SalesDocumentNotFoundException;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Repository\SalesDocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SalesDocumentController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly SalesDocumentRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/sales-documents', name: 'sales_document_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (empty($payload['contractor_id']) || empty($payload['created_by'])) {
            return new JsonResponse(['error' => 'Missing fields'], 400);
        }

        $ids = $this->resolveDocumentOwnership($payload);

        try {
            $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
                contractorId: $ids['contractorId'],
                createdBy: $ids['createdBy'],
            ));

            $id = $envelope->last(HandledStamp::class)->getResult();
        } catch (Throwable $e) {
            return $this->mapExceptionToResponse($e);
        }

        return new JsonResponse(['id' => $id], 201);
    }

    /**
     * @return array{contractorId: int, createdBy: int}
     */
    private function resolveDocumentOwnership(array $payload): array
    {
        return [
            'contractorId' => (int) $payload['contractor_id'],
            'createdBy' => (int) $payload['created_by'],
        ];
    }

    #[Route('/sales-documents/{id}/approve', name: 'sales_document_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $approvedBy = (int) ($payload['approved_by'] ?? 0);

        try {
            $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
            $resultId = $envelope->last(HandledStamp::class)->getResult();
            $document = $this->repository->getOrFail($resultId);
        } catch (Throwable $e) {
            return $this->mapExceptionToResponse($e);
        }

        return new JsonResponse([
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
            'parent_quote_id' => $document->getParentQuoteId(),
            'contractor_id' => $document->getContractorId(),
            'created_by' => $document->getCreatedBy(),
        ]);
    }

    private function mapExceptionToResponse(Throwable $e): JsonResponse
    {
        $cause = $e instanceof HandlerFailedException ? $e->getPrevious() ?? $e : $e;

        return match (true) {
            $cause instanceof SalesDocumentNotFoundException => new JsonResponse(
                ['error' => $cause->getMessage()],
                JsonResponse::HTTP_NOT_FOUND
            ),
            $cause instanceof InvalidSalesDocumentStateException => new JsonResponse(
                ['error' => $cause->getMessage()],
                JsonResponse::HTTP_CONFLICT
            ),
            default => $this->internalServerError($cause),
        };
    }

    private function internalServerError(Throwable $e): JsonResponse
    {
        $this->logger->error('Unhandled exception while processing sales document request: {message}', [
            'message' => $e->getMessage(),
            'exception' => $e,
        ]);

        return new JsonResponse(
            ['error' => 'Internal server error'],
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR
        );
    }
}
