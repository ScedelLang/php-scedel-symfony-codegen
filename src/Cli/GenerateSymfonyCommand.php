<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony\Cli;

use Scedel\Codegen\Symfony\SymfonyCodeGenerator;
use Scedel\Codegen\Symfony\SymfonyCodegenOptions;
use Scedel\Parser\ParseException;
use Scedel\Parser\ParserService;
use Scedel\Schema\Exception\SchemaBuildException;
use Scedel\Schema\Infrastructure\FilesystemIncludeResolver;
use Scedel\Schema\Infrastructure\FilesystemSourceLoader;
use Scedel\Schema\RepositoryBuilder;
use Throwable;

final class GenerateSymfonyCommand
{
    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        [$options, $positionals, $ok] = $this->parseArgs($argv);
        if (!$ok || count($positionals) !== 1) {
            $this->writeStderr(
                "Usage:\n" .
                "  generate-symfony [--output-dir <dir>] [--namespace <ns>] [--no-constructor] <schema.scedel>\n",
            );

            return 2;
        }

        $schemaPath = $positionals[0];

        try {
            $builder = new RepositoryBuilder(
                new ParserService(),
                new FilesystemIncludeResolver(),
                new FilesystemSourceLoader(),
            );
            $repository = $builder->buildFromFile($schemaPath);

            $generatorOptions = new SymfonyCodegenOptions(
                outputDir: $options['outputDir'],
                defaultNamespace: $options['namespace'],
                generateConstructors: !$options['noConstructor'],
            );

            $result = (new SymfonyCodeGenerator())->generate($repository, $generatorOptions);

            $generatedCount = 0;
            foreach ($result->files as $file) {
                $targetPath = $file->path;
                if (!str_starts_with($targetPath, '/')) {
                    $targetPath = getcwd() . '/' . $targetPath;
                }

                $directory = dirname($targetPath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                file_put_contents($targetPath, $file->contents);
                $generatedCount++;
                fwrite(STDOUT, sprintf("generated: %s\n", $targetPath));
            }

            fwrite(STDOUT, sprintf("\nGenerated %d class file(s).\n", $generatedCount));

            if ($result->warnings !== []) {
                fwrite(STDOUT, "\nWarnings:\n");
                foreach ($result->warnings as $warning) {
                    $prefix = $warning->typeName ?? 'schema';
                    if ($warning->fieldName !== null) {
                        $prefix .= '.' . $warning->fieldName;
                    }

                    fwrite(STDOUT, sprintf("- [%s] %s: %s\n", $warning->code, $prefix, $warning->message));
                }
            }

            return 0;
        } catch (Throwable $exception) {
            $this->writeStderr("Failed to generate Symfony classes:\n");
            foreach ($this->formatExceptionDetails($exception) as $line) {
                $this->writeStderr('- ' . $line . "\n");
            }

            return 2;
        }
    }

    /**
     * @param string[] $argv
     * @return array{0: array{outputDir: string, namespace: string, noConstructor: bool}, 1: array<int, string>, 2: bool}
     */
    private function parseArgs(array $argv): array
    {
        $args = $argv;
        array_shift($args);

        $outputDir = 'src/Generated/Scedel';
        $namespace = 'App\\Generated\\Scedel';
        $noConstructor = false;
        $positionals = [];

        for ($i = 0, $count = count($args); $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--output-dir') {
                $value = $args[$i + 1] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    return [
                        ['outputDir' => $outputDir, 'namespace' => $namespace, 'noConstructor' => $noConstructor],
                        [],
                        false,
                    ];
                }

                $outputDir = $value;
                $i++;
                continue;
            }

            if ($arg === '--namespace') {
                $value = $args[$i + 1] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    return [
                        ['outputDir' => $outputDir, 'namespace' => $namespace, 'noConstructor' => $noConstructor],
                        [],
                        false,
                    ];
                }

                $namespace = $value;
                $i++;
                continue;
            }

            if ($arg === '--no-constructor') {
                $noConstructor = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                return [
                    ['outputDir' => $outputDir, 'namespace' => $namespace, 'noConstructor' => $noConstructor],
                    [],
                    false,
                ];
            }

            $positionals[] = $arg;
        }

        return [
            ['outputDir' => $outputDir, 'namespace' => $namespace, 'noConstructor' => $noConstructor],
            $positionals,
            true,
        ];
    }

    private function writeStderr(string $message): void
    {
        fwrite(STDERR, $message);
    }

    /**
     * @return string[]
     */
    private function formatExceptionDetails(Throwable $exception): array
    {
        $lines = [];
        $lines[] = $exception->getMessage();

        if ($exception instanceof SchemaBuildException) {
            if ($exception->source !== null) {
                $lines[] = 'Source: ' . $exception->source->displayName;
            }

            if (count($exception->includeChain) > 1) {
                $chain = array_map(
                    static fn ($source): string => $source->displayName,
                    $exception->includeChain,
                );
                $lines[] = 'Include chain: ' . implode(' -> ', $chain);
            }
        }

        $parseException = $this->findCause($exception, ParseException::class);
        if ($parseException instanceof ParseException) {
            $location = $parseException->sourceName ?? 'unknown source';
            $line = $parseException->getParseLine();
            $column = $parseException->getParseColumn();

            if ($line !== null && $column !== null) {
                $location .= sprintf(' at %d:%d', $line, $column);
            }

            $lines[] = sprintf('Parse error in %s: %s', $location, $parseException->getMessage());
        }

        $previous = $exception->getPrevious();
        while ($previous !== null) {
            if ($parseException instanceof ParseException && $previous instanceof ParseException) {
                $previous = $previous->getPrevious();
                continue;
            }

            $message = trim($previous->getMessage());
            if ($message !== '' && $message !== trim($exception->getMessage())) {
                $lines[] = 'Caused by: ' . $message;
            }
            $previous = $previous->getPrevious();
        }

        return array_values(array_unique($lines));
    }

    private function findCause(Throwable $exception, string $className): ?Throwable
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof $className) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        return null;
    }
}
