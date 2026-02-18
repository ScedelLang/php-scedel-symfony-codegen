<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony\Model;

final readonly class GenerationWarning
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $typeName = null,
        public ?string $fieldName = null,
    ) {
    }
}
