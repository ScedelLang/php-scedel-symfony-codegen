# scedel/codegen-symfony

<img src="https://raw.githubusercontent.com/ScedelLang/grammar/5f1e7572f328d657c726a2fcaeaf53d9f6863d6a/logo.svg" width="250px" alt="logo" />

Generates Symfony-ready PHP classes from Scedel schemas (`SchemaRepository`) with `symfony/validator` attributes.

## RFC support

- [Target RFC: `0.14.2`](https://github.com/ScedelLang/grammar/blob/main/RFC-Scedel-0.14.2.md)

## What it does

- Builds DTO-like classes for custom record-like Scedel types.
- Maps many builtin Scedel constraints to Symfony `Assert\*` attributes.
- Understands control annotations under `php.*` and `php.symfony.*`.
- Returns warnings for unsupported/ambiguous constructs.

## API usage

```php
use Scedel\Codegen\Symfony\SymfonyCodeGenerator;
use Scedel\Codegen\Symfony\SymfonyCodegenOptions;

$generator = new SymfonyCodeGenerator();
$result = $generator->generate(
    $repository,
    new SymfonyCodegenOptions(
        outputDir: 'src/Dto/Scedel',
        defaultNamespace: 'App\\Dto\\Scedel',
    ),
);

foreach ($result->files as $file) {
    file_put_contents($file->path, $file->contents);
}
```

## CLI

```bash
php scedel-codegen-symfony/bin/generate-symfony.php \
  --output-dir src/Dto/Scedel \
  --namespace App\\Dto\\Scedel \
  /absolute/path/schema.scedel
```

## Supported control annotations

Type-level:

- `@php.codegen.namespace = "App\\Dto"`
- `@php.codegen.dir = "src/Dto"`
- `@php.codegen.class = "PostDto"`
- `@php.codegen.file = "PostDto.php"`
- `@php.symfony.ignore = "true"`
- `@php.symfony.validation.groups = "create,update"`
- `@php.symfony.constraint... = "..."`

Field-level:

- `@php.codegen.property = "authorEmail"`
- `@php.symfony.ignore = "true"`
- `@php.symfony.type = "?string"`
- `@php.symfony.not_blank = "true"`
- `@php.symfony.validation.groups = "create"`
- `@php.symfony.constraint... = "..."`

Custom constraint injection:

- `@php.symfony.constraint = "Length(min: 3, max: 255)"`
- `@php.symfony.constraint.primary = "NotBlank"`
- `@php.symfony.constraint.secondary = "Regex(pattern: '/^[A-Z]+$/')"`

If the value does not start with `Assert\`, generator prepends it automatically.

## Notes

- Non-record-like custom types are skipped with warnings.
- Inline record fields are generated as `array` with warnings.
- Conditional types are simplified (best-effort mapping + warnings).
- Unsupported Scedel validators/arguments are reported in warnings.
