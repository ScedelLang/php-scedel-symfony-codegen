<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony\Model;

final readonly class GenerationResult
{
    /**
     * @param GeneratedFile[] $files
     * @param GenerationWarning[] $warnings
     */
    public function __construct(
        public array $files,
        public array $warnings,
    ) {
    }
}
