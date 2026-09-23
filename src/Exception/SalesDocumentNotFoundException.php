<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SalesDocumentNotFoundException extends RuntimeException
{
    public static function withId(int $id): self
    {
        return new self("Document {$id} not found");
    }
}
