<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony\Model;

final readonly class GeneratedFile
{
    public function __construct(
        public string $typeName,
        public string $path,
        public string $contents,
    ) {
    }
}
