<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony;

final readonly class SymfonyCodegenOptions
{
    public function __construct(
        public string $outputDir = 'src/Generated/Scedel',
        public string $defaultNamespace = 'App\\Generated\\Scedel',
        public bool $generateConstructors = true,
    ) {
    }
}
