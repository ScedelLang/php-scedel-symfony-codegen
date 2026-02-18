<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Scedel\Codegen\Symfony\SymfonyCodeGenerator;
use Scedel\Codegen\Symfony\SymfonyCodegenOptions;
use Scedel\Parser\ParserService;
use Scedel\Schema\Infrastructure\FilesystemIncludeResolver;
use Scedel\Schema\Infrastructure\FilesystemSourceLoader;
use Scedel\Schema\Model\SchemaRepository;
use Scedel\Schema\RepositoryBuilder;

final class SymfonyCodeGeneratorTest extends TestCase
{
    public function testGeneratesClassWithBuiltinConstraintsAndCodegenAnnotations(): void
    {
        $source = <<<'SCED'
        type Post = {
            id: Uint
            title: String(min:5, max:255)
            tags: String(max:15)[max:10]

            @php.codegen.property = "authorEmail"
            @php.symfony.not_blank = "true"
            email: Email
        }

        @php.codegen.namespace = "App\\Dto" on Post
        @php.codegen.dir = "src/Dto" on Post
        @php.codegen.class = "PostDto" on Post
        SCED;

        $repository = $this->buildRepository($source);
        $result = (new SymfonyCodeGenerator())->generate($repository);

        self::assertCount(1, $result->files);

        $file = $result->files[0];
        self::assertSame('src/Dto/PostDto.php', $file->path);
        self::assertStringContainsString('namespace App\\Dto;', $file->contents);
        self::assertStringContainsString('final class PostDto', $file->contents);

        self::assertStringContainsString('public int $id;', $file->contents);
        self::assertStringContainsString('Assert\\Length(min: 5)', $file->contents);
        self::assertStringContainsString('Assert\\Length(max: 255)', $file->contents);

        self::assertStringContainsString('Assert\\Count(max: 10)', $file->contents);
        self::assertStringContainsString('Assert\\All(constraints: [new Assert\\Length(max: 15)])', $file->contents);

        self::assertStringContainsString('public string $authorEmail;', $file->contents);
        self::assertStringContainsString('Assert\\Email', $file->contents);
        self::assertStringContainsString('Assert\\NotBlank', $file->contents);
    }

    public function testSupportsSymfonyControlAnnotationsAndCollectsUnknownPhpWarnings(): void
    {
        $source = <<<'SCED'
        type Hidden = {
            value: String
        }

        type Post = {
            @php.symfony.constraint.main = "NotBlank"
            @php.symfony.constraint.secondary = "Length(min: 3)"
            title: String
        }

        @php.symfony.ignore = "true" on Hidden
        @php.unknown.type = "x" on Post
        @php.unknown.field = "x" on Post.title
        SCED;

        $repository = $this->buildRepository($source);
        $result = (new SymfonyCodeGenerator())->generate(
            $repository,
            new SymfonyCodegenOptions(outputDir: 'src/Generated/Scedel', defaultNamespace: 'App\\Generated\\Scedel'),
        );

        self::assertCount(1, $result->files);
        self::assertSame('Post', $result->files[0]->typeName);
        self::assertStringContainsString('Assert\\NotBlank', $result->files[0]->contents);
        self::assertStringContainsString('Assert\\Length(min: 3)', $result->files[0]->contents);

        $warningMessages = array_map(static fn ($warning): string => $warning->message, $result->warnings);
        self::assertTrue(
            $this->containsSubstring($warningMessages, 'unrecognized PHP annotation "php.unknown.type"'),
            'Expected warning for unknown type annotation.',
        );
        self::assertTrue(
            $this->containsSubstring($warningMessages, 'unrecognized PHP annotation "php.unknown.field"'),
            'Expected warning for unknown field annotation.',
        );
    }

    public function testHandlesInheritedFieldAnnotationsAndConditionalAbsentType(): void
    {
        $source = <<<'SCED'
        type Post = {
            id: Uint

            @php.symfony.ignore
            internalNote?: String
        }

        type PostWithStatus = Post & {
            status: "Draft" | "Published"
            rejectReason: when status = "Published" then String(min:2) else absent
        }
        SCED;

        $repository = $this->buildRepository($source);
        $result = (new SymfonyCodeGenerator())->generate($repository);

        self::assertCount(2, $result->files);

        $byType = [];
        foreach ($result->files as $file) {
            $byType[$file->typeName] = $file;
        }

        self::assertArrayHasKey('PostWithStatus', $byType);
        $postWithStatus = $byType['PostWithStatus']->contents;

        self::assertStringContainsString('public string $status;', $postWithStatus);
        self::assertStringContainsString("Assert\\Choice(choices: array (", $postWithStatus);
        self::assertStringNotContainsString('internalNote', $postWithStatus);

        self::assertStringContainsString('public ?string $rejectReason;', $postWithStatus);
        self::assertStringContainsString('Assert\\Length(min: 2)', $postWithStatus);

        $warningCodes = array_map(static fn ($warning): string => $warning->code, $result->warnings);
        self::assertContains('conditional_absent_simplified', $warningCodes);
    }

    private function buildRepository(string $source): SchemaRepository
    {
        $builder = new RepositoryBuilder(
            new ParserService(),
            new FilesystemIncludeResolver(),
            new FilesystemSourceLoader(),
        );

        return $builder->buildFromString($source, 'inline.scedel');
    }

    /**
     * @param string[] $items
     */
    private function containsSubstring(array $items, string $needle): bool
    {
        foreach ($items as $item) {
            if (str_contains($item, $needle)) {
                return true;
            }
        }

        return false;
    }
}
