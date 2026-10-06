<?php

declare(strict_types=1);

namespace Pds;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $safeMessage,
        private readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function publicMessage(): string
    {
        return $this->safeMessage;
    }

    public function errors(): array
    {
        return $this->fieldErrors;
    }
}
