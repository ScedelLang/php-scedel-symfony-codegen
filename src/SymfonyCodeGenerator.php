<?php

declare(strict_types=1);

namespace Scedel\Codegen\Symfony;

use Scedel\Ast\AbsentTypeNode;
use Scedel\Ast\ArgValueNode;
use Scedel\Ast\ArrayTypeNode;
use Scedel\Ast\BoolLiteralNode;
use Scedel\Ast\ConditionalTypeNode;
use Scedel\Ast\ConstraintCallArgNode;
use Scedel\Ast\ConstraintNode;
use Scedel\Ast\DefaultExprNode;
use Scedel\Ast\DictTypeNode;
use Scedel\Ast\DurationLiteralNode;
use Scedel\Ast\EmptyArrayDefaultExprNode;
use Scedel\Ast\EmptyArrayExprNode;
use Scedel\Ast\ExpressionDefaultExprNode;
use Scedel\Ast\ExpressionNode;
use Scedel\Ast\FieldNode;
use Scedel\Ast\FunctionCallExprNode;
use Scedel\Ast\IntersectionTypeNode;
use Scedel\Ast\ListArgNode;
use Scedel\Ast\LiteralDefaultExprNode;
use Scedel\Ast\LiteralNode;
use Scedel\Ast\LiteralTypeNode;
use Scedel\Ast\NamedTypeNode;
use Scedel\Ast\NullDefaultExprNode;
use Scedel\Ast\NullLiteralNode;
use Scedel\Ast\NullableNamedTypeNode;
use Scedel\Ast\NullableTypeNode;
use Scedel\Ast\NumberLiteralNode;
use Scedel\Ast\PathNode;
use Scedel\Ast\PathRootKind;
use Scedel\Ast\RecordTypeNode;
use Scedel\Ast\StringLiteralNode;
use Scedel\Ast\TypeExprNode;
use Scedel\Ast\UnionTypeNode;
use Scedel\Codegen\Symfony\Model\GeneratedFile;
use Scedel\Codegen\Symfony\Model\GenerationResult;
use Scedel\Codegen\Symfony\Model\GenerationWarning;
use Scedel\Schema\Model\AnnotationTree;
use Scedel\Schema\Model\SchemaRepository;
use Scedel\Schema\Model\TypeDefinition;

final class SymfonyCodeGenerator
{
    private const array INTEGER_TYPES = ['Int', 'Uint', 'Short', 'Ushort', 'Long', 'Ulong', 'Byte', 'Ubyte'];
    private const array FLOAT_TYPES = ['Float', 'Double'];
    private const array NUMERIC_TYPES = ['Int', 'Uint', 'Short', 'Ushort', 'Long', 'Ulong', 'Byte', 'Ubyte', 'Float', 'Double', 'Decimal'];
    private const array STRING_LENGTH_TYPES = ['String', 'Base64'];
    private const array TEMPORAL_TYPES = ['Date', 'DateTime', 'Time'];

    public function generate(SchemaRepository $repository, ?SymfonyCodegenOptions $options = null): GenerationResult
    {
        $options ??= new SymfonyCodegenOptions();

        /** @var array<string, TypeDefinition> $typesByName */
        $typesByName = [];
        foreach ($repository->customTypes() as $type) {
            $typesByName[$type->name] = $type;
        }

        /** @var GenerationWarning[] $warnings */
        $warnings = [];

        /**
         * @var array<string, array{
         *     type: TypeDefinition,
         *     namespace: string,
         *     className: string,
         *     dir: string,
         *     fileName: string,
         *     fields: array<int, array{field: FieldNode, originType: string}>,
         *     typeAnnotations: array<string, string>
         * }> $descriptors
         */
        $descriptors = [];

        ksort($typesByName);

        foreach ($typesByName as $typeName => $type) {
            $typeAnnotations = $this->flattenAnnotationTree($type->annotations);
            if ($this->parseBool($typeAnnotations['php.symfony.ignore'] ?? null, false)) {
                continue;
            }

            $fields = $this->collectRecordFields($type->expr, $typesByName, [], $typeName);
            if ($fields === null) {
                $warnings[] = new GenerationWarning(
                    code: 'skipped_non_record_type',
                    message: sprintf('Type "%s" is not record-like and was skipped.', $typeName),
                    typeName: $typeName,
                );

                continue;
            }

            $namespace = trim($typeAnnotations['php.codegen.namespace'] ?? $options->defaultNamespace);
            if ($namespace === '') {
                $namespace = $options->defaultNamespace;
            }

            $className = $this->normalizeClassName($typeAnnotations['php.codegen.class'] ?? $typeName);
            if ($className === '') {
                $warnings[] = new GenerationWarning(
                    code: 'invalid_class_name',
                    message: sprintf('Type "%s" produced empty class name. Fallback to type name.', $typeName),
                    typeName: $typeName,
                );
                $className = $typeName;
            }

            $dir = $this->normalizeDir($typeAnnotations['php.codegen.dir'] ?? $options->outputDir);
            $fileName = trim($typeAnnotations['php.codegen.file'] ?? ($className . '.php'));
            if ($fileName === '') {
                $fileName = $className . '.php';
            }

            $descriptors[$typeName] = [
                'type' => $type,
                'namespace' => $namespace,
                'className' => $className,
                'dir' => $dir,
                'fileName' => $fileName,
                'fields' => $fields,
                'typeAnnotations' => $typeAnnotations,
            ];
        }

        /** @var GeneratedFile[] $files */
        $files = [];

        foreach ($descriptors as $typeName => $descriptor) {
            $type = $descriptor['type'];
            $typeAnnotations = $descriptor['typeAnnotations'];
            $namespace = $descriptor['namespace'];
            $className = $descriptor['className'];

            $warnings = [...$warnings, ...$this->collectUnknownTypeAnnotations($typeName, $typeAnnotations)];

            $defaultGroups = $this->parseCsv($typeAnnotations['php.symfony.validation.groups'] ?? null);

            /**
             * @var array<int, array{
             *     sourceField: string,
             *     name: string,
             *     typeHint: string,
             *     docType: ?string,
             *     constraints: string[],
             *     defaultCode: ?string
             * }> $properties
             */
            $properties = [];

            foreach ($descriptor['fields'] as $entry) {
                $field = $entry['field'];
                $originTypeName = $entry['originType'];

                $originType = $typesByName[$originTypeName] ?? null;
                $baseFieldAnnotations = $originType !== null
                    ? $this->flattenAnnotationTree($originType->fieldAnnotationsAt([$field->name]))
                    : [];
                $overrideFieldAnnotations = $this->flattenAnnotationTree($type->fieldAnnotationsAt([$field->name]));
                $fieldAnnotations = array_merge($baseFieldAnnotations, $overrideFieldAnnotations);

                if ($this->parseBool($fieldAnnotations['php.symfony.ignore'] ?? null, false)) {
                    continue;
                }

                $warnings = [...$warnings, ...$this->collectUnknownFieldAnnotations($typeName, $field->name, $fieldAnnotations)];

                $propertyName = $this->normalizePropertyName($fieldAnnotations['php.codegen.property'] ?? $field->name);
                if ($propertyName === '') {
                    $warnings[] = new GenerationWarning(
                        code: 'invalid_property_name',
                        message: sprintf(
                            'Type "%s" field "%s" produced empty property name and was skipped.',
                            $typeName,
                            $field->name,
                        ),
                        typeName: $typeName,
                        fieldName: $field->name,
                    );
                    continue;
                }

                $analysis = $this->analyzeType(
                    type: $field->type,
                    repository: $repository,
                    typesByName: $typesByName,
                    descriptors: $descriptors,
                    currentTypeName: $typeName,
                    currentNamespace: $namespace,
                    fieldName: $field->name,
                    stack: [],
                );

                $warnings = [...$warnings, ...$analysis['warnings']];

                if ($field->optional) {
                    $analysis['optional'] = true;
                    $analysis['nullable'] = true;
                }

                $fieldGroups = $this->parseCsv($fieldAnnotations['php.symfony.validation.groups'] ?? null) ?? $defaultGroups;

                $constraints = $analysis['constraints'];

                if ($analysis['requiresValid']) {
                    $constraints[] = 'Assert\\Valid';
                }

                if ($this->parseBool($fieldAnnotations['php.symfony.not_blank'] ?? null, false)) {
                    $constraints[] = 'Assert\\NotBlank';
                }

                $customFieldConstraints = $this->collectCustomSymfonyConstraints($fieldAnnotations);
                $constraints = [...$constraints, ...$customFieldConstraints];

                if (!$analysis['nullable'] && !$analysis['knownNullOnly']) {
                    $constraints = ['Assert\\NotNull', ...$constraints];
                }

                $constraints = $this->deduplicateConstraints(
                    array_map(
                        fn (string $constraint): string => $this->withGroups($constraint, $fieldGroups),
                        $constraints,
                    ),
                );

                $defaultCode = $this->compileDefaultCode($field->default, $typeName, $field->name, $warnings);
                if ($defaultCode === 'null') {
                    $analysis['nullable'] = true;
                }

                if ($analysis['optional'] && $defaultCode === null) {
                    $defaultCode = 'null';
                    $analysis['nullable'] = true;
                }

                $phpTypeOverride = trim($fieldAnnotations['php.symfony.type'] ?? '');
                $typeHint = $phpTypeOverride !== ''
                    ? $phpTypeOverride
                    : $this->buildTypeHint($analysis['typeHint'], $analysis['nullable']);

                $properties[] = [
                    'sourceField' => $field->name,
                    'name' => $propertyName,
                    'typeHint' => $typeHint,
                    'docType' => $analysis['docType'],
                    'constraints' => $constraints,
                    'defaultCode' => $defaultCode,
                ];
            }

            $classConstraints = array_map(
                fn (string $constraint): string => $this->withGroups($constraint, $defaultGroups),
                $this->collectCustomSymfonyConstraints($typeAnnotations),
            );

            $contents = $this->renderClass(
                namespace: $namespace,
                className: $className,
                classConstraints: $classConstraints,
                properties: $properties,
                generateConstructor: $options->generateConstructors,
            );

            $path = $this->buildFilePath($descriptor['dir'], $descriptor['fileName']);
            $files[] = new GeneratedFile($typeName, $path, $contents);
        }

        return new GenerationResult($files, $warnings);
    }

    /**
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, true> $stack
     * @return array<int, array{field: FieldNode, originType: string}>|null
     */
    private function collectRecordFields(
        TypeExprNode $type,
        array $typesByName,
        array $stack,
        string $originType,
    ): ?array {
        if ($type instanceof RecordTypeNode) {
            $result = [];
            foreach ($type->fields as $field) {
                $result[] = ['field' => $field, 'originType' => $originType];
            }

            return $result;
        }

        if ($type instanceof IntersectionTypeNode) {
            /** @var array<string, array{field: FieldNode, originType: string}> $byName */
            $byName = [];
            $ordered = [];

            foreach ($type->items as $item) {
                $itemFields = $this->collectRecordFields($item, $typesByName, $stack, $originType);
                if ($itemFields === null) {
                    return null;
                }

                foreach ($itemFields as $entry) {
                    $fieldName = $entry['field']->name;
                    if (!isset($byName[$fieldName])) {
                        $ordered[] = $fieldName;
                    }

                    $byName[$fieldName] = $entry;
                }
            }

            $result = [];
            foreach ($ordered as $fieldName) {
                $result[] = $byName[$fieldName];
            }

            return $result;
        }

        if ($type instanceof NamedTypeNode || $type instanceof NullableNamedTypeNode) {
            $name = $type->name;
            if (isset($stack[$name])) {
                return null;
            }

            $definition = $typesByName[$name] ?? null;
            if ($definition === null) {
                return null;
            }

            $stack[$name] = true;

            return $this->collectRecordFields($definition->expr, $typesByName, $stack, $name);
        }

        if ($type instanceof NullableTypeNode) {
            return $this->collectRecordFields($type->innerType, $typesByName, $stack, $originType);
        }

        return null;
    }

    /**
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, array{
     *     type: TypeDefinition,
     *     namespace: string,
     *     className: string,
     *     dir: string,
     *     fileName: string,
     *     fields: array<int, array{field: FieldNode, originType: string}>,
     *     typeAnnotations: array<string, string>
     * }> $descriptors
     * @param array<string, true> $stack
     * @return array{
     *     typeHint: string,
     *     docType: ?string,
     *     nullable: bool,
     *     optional: bool,
     *     constraints: string[],
     *     requiresValid: bool,
     *     builtinTarget: ?string,
     *     knownNullOnly: bool,
     *     warnings: GenerationWarning[]
     * }
     */
    private function analyzeType(
        TypeExprNode $type,
        SchemaRepository $repository,
        array $typesByName,
        array $descriptors,
        string $currentTypeName,
        string $currentNamespace,
        string $fieldName,
        array $stack,
    ): array {
        if ($type instanceof NullableTypeNode) {
            $analysis = $this->analyzeType(
                $type->innerType,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
            $analysis['nullable'] = true;

            return $analysis;
        }

        if ($type instanceof NullableNamedTypeNode) {
            $analysis = $this->analyzeNamedType(
                $type->name,
                [],
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
            $analysis['nullable'] = true;

            return $analysis;
        }

        if ($type instanceof NamedTypeNode) {
            return $this->analyzeNamedType(
                $type->name,
                $type->constraints,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
        }

        if ($type instanceof ArrayTypeNode) {
            $item = $this->analyzeType(
                $type->itemType,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );

            $warnings = $item['warnings'];
            $constraints = ['Assert\\Type(type: "array")'];
            $constraints = [...$constraints, ...$this->mapConstraints($type->constraints, 'Array', null, $fieldName, $warnings)];

            $itemConstraintExpressions = [];
            if ($item['requiresValid']) {
                $itemConstraintExpressions[] = 'new Assert\\Valid';
            }

            foreach ($item['constraints'] as $constraint) {
                $itemConstraintExpressions[] = 'new ' . $constraint;
            }

            if ($itemConstraintExpressions !== []) {
                $constraints[] = 'Assert\\All(constraints: [' . implode(', ', $itemConstraintExpressions) . '])';
            }

            $docType = $item['docType'] ?? $this->docTypeFromTypeHint($item['typeHint']);

            return [
                'typeHint' => 'array',
                'docType' => sprintf('list<%s>', $docType),
                'nullable' => false,
                'optional' => false,
                'constraints' => $constraints,
                'requiresValid' => false,
                'builtinTarget' => 'Array',
                'knownNullOnly' => false,
                'warnings' => $warnings,
            ];
        }

        if ($type instanceof DictTypeNode) {
            $value = $this->analyzeType(
                $type->valueType,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );

            $warnings = $value['warnings'];
            $constraints = ['Assert\\Type(type: "array")'];

            $valueConstraintExpressions = [];
            if ($value['requiresValid']) {
                $valueConstraintExpressions[] = 'new Assert\\Valid';
            }
            foreach ($value['constraints'] as $constraint) {
                $valueConstraintExpressions[] = 'new ' . $constraint;
            }
            if ($valueConstraintExpressions !== []) {
                $constraints[] = 'Assert\\All(constraints: [' . implode(', ', $valueConstraintExpressions) . '])';
            }

            $valueDocType = $value['docType'] ?? $this->docTypeFromTypeHint($value['typeHint']);

            return [
                'typeHint' => 'array',
                'docType' => sprintf('array<string, %s>', $valueDocType),
                'nullable' => false,
                'optional' => false,
                'constraints' => $constraints,
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => $warnings,
            ];
        }

        if ($type instanceof RecordTypeNode) {
            return [
                'typeHint' => 'array',
                'docType' => 'array<string, mixed>',
                'nullable' => false,
                'optional' => false,
                'constraints' => ['Assert\\Type(type: "array")'],
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => [
                    new GenerationWarning(
                        code: 'inline_record_as_array',
                        message: sprintf(
                            'Field "%s" in type "%s" is an inline record; generated as array.',
                            $fieldName,
                            $currentTypeName,
                        ),
                        typeName: $currentTypeName,
                        fieldName: $fieldName,
                    ),
                ],
            ];
        }

        if ($type instanceof LiteralTypeNode) {
            [$literalTypeHint, $literalValue] = $this->literalTypeAndValue($type->literal);

            return [
                'typeHint' => $literalTypeHint,
                'docType' => null,
                'nullable' => $literalValue === null,
                'optional' => false,
                'constraints' => [
                    'Assert\\EqualTo(value: ' . $this->exportPhp($literalValue) . ')',
                ],
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => $literalValue === null,
                'warnings' => [],
            ];
        }

        if ($type instanceof UnionTypeNode) {
            return $this->analyzeUnionType(
                $type,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
        }

        if ($type instanceof IntersectionTypeNode) {
            return $this->analyzeIntersectionType(
                $type,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
        }

        if ($type instanceof ConditionalTypeNode) {
            return $this->analyzeConditionalType(
                $type,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
        }

        if ($type instanceof AbsentTypeNode) {
            return [
                'typeHint' => 'mixed',
                'docType' => null,
                'nullable' => true,
                'optional' => true,
                'constraints' => [],
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => [],
            ];
        }

        return [
            'typeHint' => 'mixed',
            'docType' => null,
            'nullable' => true,
            'optional' => false,
            'constraints' => [],
            'requiresValid' => false,
            'builtinTarget' => null,
            'knownNullOnly' => false,
            'warnings' => [
                new GenerationWarning(
                    code: 'unsupported_type_node',
                    message: sprintf(
                        'Field "%s" in type "%s" has unsupported type node "%s"; generated as mixed.',
                        $fieldName,
                        $currentTypeName,
                        $type::class,
                    ),
                    typeName: $currentTypeName,
                    fieldName: $fieldName,
                ),
            ],
        ];
    }

    /**
     * @param ConstraintNode[] $constraints
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, array{
     *     type: TypeDefinition,
     *     namespace: string,
     *     className: string,
     *     dir: string,
     *     fileName: string,
     *     fields: array<int, array{field: FieldNode, originType: string}>,
     *     typeAnnotations: array<string, string>
     * }> $descriptors
     * @param array<string, true> $stack
     * @return array{
     *     typeHint: string,
     *     docType: ?string,
     *     nullable: bool,
     *     optional: bool,
     *     constraints: string[],
     *     requiresValid: bool,
     *     builtinTarget: ?string,
     *     knownNullOnly: bool,
     *     warnings: GenerationWarning[]
     * }
     */
    private function analyzeNamedType(
        string $name,
        array $constraints,
        SchemaRepository $repository,
        array $typesByName,
        array $descriptors,
        string $currentTypeName,
        string $currentNamespace,
        string $fieldName,
        array $stack,
    ): array {
        $warnings = [];

        $builtin = $this->builtinTypeInfo($name);
        if ($builtin !== null) {
            $mapped = $this->mapConstraints($constraints, $name, null, $fieldName, $warnings);

            return [
                'typeHint' => $builtin['typeHint'],
                'docType' => $builtin['docType'],
                'nullable' => $builtin['nullable'],
                'optional' => false,
                'constraints' => [...$builtin['constraints'], ...$mapped],
                'requiresValid' => false,
                'builtinTarget' => $name,
                'knownNullOnly' => $name === 'Null',
                'warnings' => $warnings,
            ];
        }

        $descriptor = $descriptors[$name] ?? null;
        if ($descriptor !== null) {
            $typeHint = $this->classTypeHint($descriptor['namespace'], $descriptor['className'], $currentNamespace);

            $customMapped = $this->mapConstraints($constraints, $name, null, $fieldName, $warnings);

            return [
                'typeHint' => $typeHint,
                'docType' => null,
                'nullable' => false,
                'optional' => false,
                'constraints' => $customMapped,
                'requiresValid' => true,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => $warnings,
            ];
        }

        if (isset($stack[$name])) {
            return [
                'typeHint' => 'mixed',
                'docType' => null,
                'nullable' => true,
                'optional' => false,
                'constraints' => [],
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => [
                    new GenerationWarning(
                        code: 'recursive_type_alias',
                        message: sprintf(
                            'Field "%s" in type "%s" references recursive alias "%s"; generated as mixed.',
                            $fieldName,
                            $currentTypeName,
                            $name,
                        ),
                        typeName: $currentTypeName,
                        fieldName: $fieldName,
                    ),
                ],
            ];
        }

        $definition = $typesByName[$name] ?? null;
        if ($definition === null) {
            return [
                'typeHint' => 'mixed',
                'docType' => null,
                'nullable' => true,
                'optional' => false,
                'constraints' => [],
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => [
                    new GenerationWarning(
                        code: 'unknown_named_type',
                        message: sprintf(
                            'Field "%s" in type "%s" references unknown type "%s"; generated as mixed.',
                            $fieldName,
                            $currentTypeName,
                            $name,
                        ),
                        typeName: $currentTypeName,
                        fieldName: $fieldName,
                    ),
                ],
            ];
        }

        $stack[$name] = true;

        $resolved = $this->analyzeType(
            $definition->expr,
            $repository,
            $typesByName,
            $descriptors,
            $currentTypeName,
            $currentNamespace,
            $fieldName,
            $stack,
        );

        $warnings = [...$warnings, ...$resolved['warnings']];

        $fallbackBuiltin = $resolved['builtinTarget'];
        $extraConstraints = $this->mapConstraints($constraints, $name, $fallbackBuiltin, $fieldName, $warnings);

        return [
            'typeHint' => $resolved['typeHint'],
            'docType' => $resolved['docType'],
            'nullable' => $resolved['nullable'],
            'optional' => $resolved['optional'],
            'constraints' => [...$resolved['constraints'], ...$extraConstraints],
            'requiresValid' => $resolved['requiresValid'],
            'builtinTarget' => $resolved['builtinTarget'],
            'knownNullOnly' => $resolved['knownNullOnly'],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, array{
     *     type: TypeDefinition,
     *     namespace: string,
     *     className: string,
     *     dir: string,
     *     fileName: string,
     *     fields: array<int, array{field: FieldNode, originType: string}>,
     *     typeAnnotations: array<string, string>
     * }> $descriptors
     * @param array<string, true> $stack
     * @return array{
     *     typeHint: string,
     *     docType: ?string,
     *     nullable: bool,
     *     optional: bool,
     *     constraints: string[],
     *     requiresValid: bool,
     *     builtinTarget: ?string,
     *     knownNullOnly: bool,
     *     warnings: GenerationWarning[]
     * }
     */
    private function analyzeUnionType(
        UnionTypeNode $type,
        SchemaRepository $repository,
        array $typesByName,
        array $descriptors,
        string $currentTypeName,
        string $currentNamespace,
        string $fieldName,
        array $stack,
    ): array {
        $warnings = [];

        $nullable = false;
        $optional = false;
        $literalChoices = [];
        $literalChoiceType = null;
        $nonLiteralItems = [];

        foreach ($type->items as $item) {
            if ($item instanceof AbsentTypeNode) {
                $optional = true;
                $nullable = true;
                continue;
            }

            if ($item instanceof LiteralTypeNode) {
                [$literalType, $literalValue] = $this->literalTypeAndValue($item->literal);
                if ($literalValue === null) {
                    $nullable = true;
                    continue;
                }

                if ($literalChoiceType === null) {
                    $literalChoiceType = $literalType;
                }

                if ($literalChoiceType !== $literalType) {
                    $nonLiteralItems[] = $item;
                    continue;
                }

                $literalChoices[] = $literalValue;
                continue;
            }

            $nonLiteralItems[] = $item;
        }

        if ($nonLiteralItems === [] && $literalChoices !== []) {
            $constraints = [
                'Assert\\Choice(choices: ' . $this->exportPhp(array_values(array_unique($literalChoices))) . ')',
            ];

            return [
                'typeHint' => $literalChoiceType ?? 'mixed',
                'docType' => null,
                'nullable' => $nullable,
                'optional' => $optional,
                'constraints' => $constraints,
                'requiresValid' => false,
                'builtinTarget' => null,
                'knownNullOnly' => false,
                'warnings' => $warnings,
            ];
        }

        if (count($nonLiteralItems) === 1 && $literalChoices === []) {
            $analysis = $this->analyzeType(
                $nonLiteralItems[0],
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );

            $analysis['nullable'] = $analysis['nullable'] || $nullable;
            $analysis['optional'] = $analysis['optional'] || $optional;

            return $analysis;
        }

        $itemAnalyses = [];
        foreach ($nonLiteralItems as $item) {
            $itemAnalysis = $this->analyzeType(
                $item,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );
            $warnings = [...$warnings, ...$itemAnalysis['warnings']];
            $itemAnalyses[] = $itemAnalysis;
        }

        $sharedTypeHint = null;
        $sharedDocType = null;
        $requiresValid = false;
        $constraints = [];

        foreach ($itemAnalyses as $itemAnalysis) {
            $sharedTypeHint ??= $itemAnalysis['typeHint'];
            if ($sharedTypeHint !== $itemAnalysis['typeHint']) {
                $sharedTypeHint = 'mixed';
            }

            $sharedDocType ??= $itemAnalysis['docType'];
            if ($sharedDocType !== $itemAnalysis['docType']) {
                $sharedDocType = null;
            }

            $requiresValid = $requiresValid || $itemAnalysis['requiresValid'];
            $constraints = [...$constraints, ...$itemAnalysis['constraints']];
            $nullable = $nullable || $itemAnalysis['nullable'];
            $optional = $optional || $itemAnalysis['optional'];
        }

        if ($literalChoices !== []) {
            $constraints[] = 'Assert\\Choice(choices: ' . $this->exportPhp(array_values(array_unique($literalChoices))) . ')';
            if ($sharedTypeHint === 'mixed' && $literalChoiceType !== null) {
                $sharedTypeHint = $literalChoiceType;
            }
        }

        if ($sharedTypeHint === null) {
            $sharedTypeHint = 'mixed';
        }

        if (count($nonLiteralItems) > 1 && $sharedTypeHint === 'mixed') {
            $warnings[] = new GenerationWarning(
                code: 'union_mixed_type',
                message: sprintf(
                    'Field "%s" in type "%s" has heterogeneous union; generated as mixed.',
                    $fieldName,
                    $currentTypeName,
                ),
                typeName: $currentTypeName,
                fieldName: $fieldName,
            );
        }

        return [
            'typeHint' => $sharedTypeHint,
            'docType' => $sharedDocType,
            'nullable' => $nullable,
            'optional' => $optional,
            'constraints' => $this->deduplicateConstraints($constraints),
            'requiresValid' => $requiresValid,
            'builtinTarget' => null,
            'knownNullOnly' => false,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, array{
     *     type: TypeDefinition,
     *     namespace: string,
     *     className: string,
     *     dir: string,
     *     fileName: string,
     *     fields: array<int, array{field: FieldNode, originType: string}>,
     *     typeAnnotations: array<string, string>
     * }> $descriptors
     * @param array<string, true> $stack
     * @return array{
     *     typeHint: string,
     *     docType: ?string,
     *     nullable: bool,
     *     optional: bool,
     *     constraints: string[],
     *     requiresValid: bool,
     *     builtinTarget: ?string,
     *     knownNullOnly: bool,
     *     warnings: GenerationWarning[]
     * }
     */
    private function analyzeIntersectionType(
        IntersectionTypeNode $type,
        SchemaRepository $repository,
        array $typesByName,
        array $descriptors,
        string $currentTypeName,
        string $currentNamespace,
        string $fieldName,
        array $stack,
    ): array {
        $warnings = [];
        $constraints = [];
        $nullable = false;
        $optional = false;
        $requiresValid = false;
        $typeHint = null;
        $docType = null;
        $builtinTarget = null;
        $knownNullOnly = false;

        foreach ($type->items as $item) {
            $analysis = $this->analyzeType(
                $item,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );

            $warnings = [...$warnings, ...$analysis['warnings']];
            $constraints = [...$constraints, ...$analysis['constraints']];
            $nullable = $nullable || $analysis['nullable'];
            $optional = $optional || $analysis['optional'];
            $requiresValid = $requiresValid || $analysis['requiresValid'];
            $knownNullOnly = $knownNullOnly || $analysis['knownNullOnly'];

            $typeHint ??= $analysis['typeHint'];
            if ($typeHint !== $analysis['typeHint']) {
                $typeHint = 'mixed';
            }

            $docType ??= $analysis['docType'];
            if ($docType !== $analysis['docType']) {
                $docType = null;
            }

            if ($builtinTarget === null) {
                $builtinTarget = $analysis['builtinTarget'];
            } elseif ($analysis['builtinTarget'] !== $builtinTarget) {
                $builtinTarget = null;
            }
        }

        return [
            'typeHint' => $typeHint ?? 'mixed',
            'docType' => $docType,
            'nullable' => $nullable,
            'optional' => $optional,
            'constraints' => $this->deduplicateConstraints($constraints),
            'requiresValid' => $requiresValid,
            'builtinTarget' => $builtinTarget,
            'knownNullOnly' => $knownNullOnly,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, TypeDefinition> $typesByName
     * @param array<string, array{
     *     type: TypeDefinition,
     *     namespace: string,
     *     className: string,
     *     dir: string,
     *     fileName: string,
     *     fields: array<int, array{field: FieldNode, originType: string}>,
     *     typeAnnotations: array<string, string>
     * }> $descriptors
     * @param array<string, true> $stack
     * @return array{
     *     typeHint: string,
     *     docType: ?string,
     *     nullable: bool,
     *     optional: bool,
     *     constraints: string[],
     *     requiresValid: bool,
     *     builtinTarget: ?string,
     *     knownNullOnly: bool,
     *     warnings: GenerationWarning[]
     * }
     */
    private function analyzeConditionalType(
        ConditionalTypeNode $type,
        SchemaRepository $repository,
        array $typesByName,
        array $descriptors,
        string $currentTypeName,
        string $currentNamespace,
        string $fieldName,
        array $stack,
    ): array {
        if ($type->elseType instanceof AbsentTypeNode) {
            $thenAnalysis = $this->analyzeType(
                $type->thenType,
                $repository,
                $typesByName,
                $descriptors,
                $currentTypeName,
                $currentNamespace,
                $fieldName,
                $stack,
            );

            $thenAnalysis['optional'] = true;
            $thenAnalysis['nullable'] = true;
            $thenAnalysis['warnings'][] = new GenerationWarning(
                code: 'conditional_absent_simplified',
                message: sprintf(
                    'Field "%s" in type "%s" has conditional/absent type; generated as nullable optional field.',
                    $fieldName,
                    $currentTypeName,
                ),
                typeName: $currentTypeName,
                fieldName: $fieldName,
            );

            return $thenAnalysis;
        }

        $thenAnalysis = $this->analyzeType(
            $type->thenType,
            $repository,
            $typesByName,
            $descriptors,
            $currentTypeName,
            $currentNamespace,
            $fieldName,
            $stack,
        );

        $elseAnalysis = $this->analyzeType(
            $type->elseType,
            $repository,
            $typesByName,
            $descriptors,
            $currentTypeName,
            $currentNamespace,
            $fieldName,
            $stack,
        );

        $warnings = [
            ...$thenAnalysis['warnings'],
            ...$elseAnalysis['warnings'],
            new GenerationWarning(
                code: 'conditional_type_simplified',
                message: sprintf(
                    'Field "%s" in type "%s" has conditional type; generated as broad PHP type.',
                    $fieldName,
                    $currentTypeName,
                ),
                typeName: $currentTypeName,
                fieldName: $fieldName,
            ),
        ];

        $typeHint = $thenAnalysis['typeHint'] === $elseAnalysis['typeHint']
            ? $thenAnalysis['typeHint']
            : 'mixed';
        $docType = $thenAnalysis['docType'] === $elseAnalysis['docType']
            ? $thenAnalysis['docType']
            : null;

        return [
            'typeHint' => $typeHint,
            'docType' => $docType,
            'nullable' => $thenAnalysis['nullable'] || $elseAnalysis['nullable'],
            'optional' => $thenAnalysis['optional'] || $elseAnalysis['optional'],
            'constraints' => $this->deduplicateConstraints([...$thenAnalysis['constraints'], ...$elseAnalysis['constraints']]),
            'requiresValid' => $thenAnalysis['requiresValid'] || $elseAnalysis['requiresValid'],
            'builtinTarget' => null,
            'knownNullOnly' => $thenAnalysis['knownNullOnly'] && $elseAnalysis['knownNullOnly'],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param ConstraintNode[] $constraints
     * @param GenerationWarning[] $warnings
     * @return string[]
     */
    private function mapConstraints(
        array $constraints,
        string $targetType,
        ?string $fallbackBuiltinTarget,
        string $fieldName,
        array &$warnings,
    ): array {
        $result = [];

        foreach ($constraints as $constraint) {
            if ($constraint->negated) {
                $warnings[] = new GenerationWarning(
                    code: 'unsupported_negated_constraint',
                    message: sprintf(
                        'Constraint "%s" on field "%s" is negated and cannot be mapped directly to Symfony.',
                        $constraint->name,
                        $fieldName,
                    ),
                );
                continue;
            }

            $mapped = $this->mapSingleConstraint($constraint, $targetType, $fallbackBuiltinTarget, $fieldName, $warnings);
            foreach ($mapped as $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param GenerationWarning[] $warnings
     * @return string[]
     */
    private function mapSingleConstraint(
        ConstraintNode $constraint,
        string $targetType,
        ?string $fallbackBuiltinTarget,
        string $fieldName,
        array &$warnings,
    ): array {
        $effectiveTarget = $this->resolveConstraintTarget($targetType, $fallbackBuiltinTarget);
        if ($effectiveTarget === null) {
            $warnings[] = new GenerationWarning(
                code: 'unknown_constraint_target',
                message: sprintf(
                    'Constraint "%s" on field "%s" has unsupported target type "%s".',
                    $constraint->name,
                    $fieldName,
                    $targetType,
                ),
            );

            return [];
        }

        $argument = $this->readConstraintArgument($constraint);
        $name = $constraint->name;

        if (in_array($effectiveTarget, self::STRING_LENGTH_TYPES, true) && ($name === 'min' || $name === 'max')) {
            if ($argument['kind'] !== 'literal' || !is_int($argument['value'])) {
                return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
            }

            $key = $name === 'min' ? 'min' : 'max';
            return [sprintf('Assert\\Length(%s: %d)', $key, $argument['value'])];
        }

        if ($effectiveTarget === 'Array' && ($name === 'min' || $name === 'max')) {
            if ($argument['kind'] !== 'literal' || !is_int($argument['value'])) {
                return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
            }

            $key = $name === 'min' ? 'min' : 'max';
            return [sprintf('Assert\\Count(%s: %d)', $key, $argument['value'])];
        }

        if (in_array($effectiveTarget, self::NUMERIC_TYPES, true) && in_array($name, ['min', 'max', 'less', 'greater'], true)) {
            return $this->mapComparableConstraint($name, $argument, $warnings, $constraint, $fieldName);
        }

        if (in_array($effectiveTarget, self::TEMPORAL_TYPES, true) && in_array($name, ['min', 'max', 'format'], true)) {
            if ($name === 'format') {
                if ($argument['kind'] !== 'literal' || !is_string($argument['value'])) {
                    return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
                }

                $phpFormat = $this->toPhpDateFormat($argument['value']);
                $constraintName = match ($effectiveTarget) {
                    'Date' => 'Assert\\Date',
                    'DateTime' => 'Assert\\DateTime',
                    'Time' => 'Assert\\Time',
                    default => 'Assert\\DateTime',
                };

                return [sprintf('%s(format: %s)', $constraintName, $this->exportPhp($phpFormat))];
            }

            return $this->mapComparableConstraint($name, $argument, $warnings, $constraint, $fieldName);
        }

        if ($effectiveTarget === 'Duration' && in_array($name, ['min', 'max'], true)) {
            return $this->mapComparableConstraint($name, $argument, $warnings, $constraint, $fieldName);
        }

        if (in_array($effectiveTarget, ['String', 'Base64'], true) && in_array($name, ['regex', 'format'], true)) {
            if ($argument['kind'] !== 'literal' || !is_string($argument['value'])) {
                return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
            }

            $pattern = $this->normalizeRegexPattern($argument['value']);

            return [sprintf('Assert\\Regex(pattern: %s)', $this->exportPhp($pattern))];
        }

        if ($effectiveTarget === 'Decimal' && $name === 'precision') {
            if ($argument['kind'] !== 'literal' || !is_int($argument['value']) || $argument['value'] < 0) {
                return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
            }

            $scale = $argument['value'];
            $pattern = sprintf('/^[+-]?\\d+(?:\\.\\d{1,%d})?$/', $scale);

            return [sprintf('Assert\\Regex(pattern: %s)', $this->exportPhp($pattern))];
        }

        if ($effectiveTarget === 'Url' && $name === 'scheme') {
            $protocols = $this->constraintArgumentAsStringList($argument);
            if ($protocols === null || $protocols === []) {
                return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
            }

            return [sprintf('Assert\\Url(protocols: %s)', $this->exportPhp($protocols))];
        }

        if ($effectiveTarget === 'Url' && $name === 'domain') {
            $warnings[] = new GenerationWarning(
                code: 'unsupported_url_domain_constraint',
                message: sprintf('Constraint "domain" on field "%s" for Url is not mapped automatically.', $fieldName),
            );
            return [];
        }

        if ($effectiveTarget === 'Email' && $name === 'domain') {
            $warnings[] = new GenerationWarning(
                code: 'unsupported_email_domain_constraint',
                message: sprintf('Constraint "domain" on field "%s" for Email is not mapped automatically.', $fieldName),
            );
            return [];
        }

        if (in_array($effectiveTarget, ['Ip', 'IpV4', 'IpV6'], true) && in_array($name, ['subnet', 'mask'], true)) {
            $warnings[] = new GenerationWarning(
                code: 'unsupported_ip_constraint',
                message: sprintf('Constraint "%s" on field "%s" is not mapped automatically for IP values.', $name, $fieldName),
            );
            return [];
        }

        $warnings[] = new GenerationWarning(
            code: 'unsupported_constraint',
            message: sprintf(
                'Constraint "%s" on field "%s" (target "%s") is not supported by the generator.',
                $name,
                $fieldName,
                $targetType,
            ),
        );

        return [];
    }

    /**
     * @param array{kind: string, value: mixed} $argument
     * @param GenerationWarning[] $warnings
     * @return string[]
     */
    private function mapComparableConstraint(
        string $name,
        array $argument,
        array &$warnings,
        ConstraintNode $constraint,
        string $fieldName,
    ): array {
        $constraintName = match ($name) {
            'min' => 'Assert\\GreaterThanOrEqual',
            'max' => 'Assert\\LessThanOrEqual',
            'less' => 'Assert\\LessThan',
            'greater' => 'Assert\\GreaterThan',
            default => null,
        };

        if ($constraintName === null) {
            return [];
        }

        if ($argument['kind'] === 'property_path' && is_string($argument['value'])) {
            return [sprintf('%s(propertyPath: %s)', $constraintName, $this->exportPhp($argument['value']))];
        }

        if ($argument['kind'] === 'literal') {
            return [sprintf('%s(value: %s)', $constraintName, $this->exportPhp($argument['value']))];
        }

        return $this->warnUnsupportedArgument($warnings, $constraint, $fieldName);
    }

    /**
     * @param GenerationWarning[] $warnings
     * @return string[]
     */
    private function warnUnsupportedArgument(array &$warnings, ConstraintNode $constraint, string $fieldName): array
    {
        $warnings[] = new GenerationWarning(
            code: 'unsupported_constraint_argument',
            message: sprintf(
                'Constraint "%s" on field "%s" has unsupported argument for Symfony mapping.',
                $constraint->name,
                $fieldName,
            ),
        );

        return [];
    }

    /**
     * @param array{kind: string, value: mixed} $argument
     * @return string[]|null
     */
    private function constraintArgumentAsStringList(array $argument): ?array
    {
        if ($argument['kind'] === 'literal' && is_string($argument['value'])) {
            return [$argument['value']];
        }

        if ($argument['kind'] !== 'list' || !is_array($argument['value'])) {
            return null;
        }

        $result = [];
        foreach ($argument['value'] as $item) {
            if (!is_string($item)) {
                return null;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array{kind: string, value: mixed}
     */
    private function readConstraintArgument(ConstraintNode $constraint): array
    {
        if ($constraint->usesCallSyntax) {
            if ($constraint->callArgs === []) {
                return ['kind' => 'none', 'value' => null];
            }

            if (count($constraint->callArgs) > 1) {
                return ['kind' => 'unsupported', 'value' => null];
            }

            return $this->readCallArg($constraint->callArgs[0]);
        }

        if ($constraint->arg === null) {
            return ['kind' => 'none', 'value' => null];
        }

        return $this->readArgValue($constraint->arg);
    }

    /**
     * @return array{kind: string, value: mixed}
     */
    private function readCallArg(ConstraintCallArgNode $arg): array
    {
        if ($arg->name !== null) {
            return ['kind' => 'unsupported', 'value' => null];
        }

        return $this->readExpressionArg($arg->value);
    }

    /**
     * @return array{kind: string, value: mixed}
     */
    private function readArgValue(ArgValueNode $arg): array
    {
        if ($arg instanceof ListArgNode) {
            $items = [];
            foreach ($arg->items as $item) {
                $parsed = $this->readExpressionArg($item);
                if ($parsed['kind'] !== 'literal') {
                    return ['kind' => 'unsupported', 'value' => null];
                }

                $items[] = $parsed['value'];
            }

            return ['kind' => 'list', 'value' => $items];
        }

        return $this->readExpressionArg($arg->value);
    }

    /**
     * @return array{kind: string, value: mixed}
     */
    private function readExpressionArg(ExpressionNode $expression): array
    {
        if ($expression instanceof LiteralNode) {
            return ['kind' => 'literal', 'value' => $this->literalToPhp($expression)];
        }

        if ($expression instanceof EmptyArrayExprNode) {
            return ['kind' => 'list', 'value' => []];
        }

        if ($expression instanceof PathNode) {
            $path = $this->pathToPropertyPath($expression);
            if ($path === null) {
                return ['kind' => 'unsupported', 'value' => null];
            }

            return ['kind' => 'property_path', 'value' => $path];
        }

        if ($expression instanceof FunctionCallExprNode) {
            return ['kind' => 'unsupported', 'value' => null];
        }

        return ['kind' => 'unsupported', 'value' => null];
    }

    private function pathToPropertyPath(PathNode $path): ?string
    {
        if ($path->rootKind === PathRootKind::THIS) {
            if ($path->segments === []) {
                return null;
            }

            return implode('.', $path->segments);
        }

        if ($path->rootKind === PathRootKind::IDENTIFIER) {
            if ($path->rootName === null || $path->rootName === '') {
                return null;
            }

            if ($path->segments === []) {
                return $path->rootName;
            }

            return $path->rootName . '.' . implode('.', $path->segments);
        }

        return null;
    }

    /**
     * @param GenerationWarning[] $warnings
     */
    private function compileDefaultCode(
        ?DefaultExprNode $default,
        string $typeName,
        string $fieldName,
        array &$warnings,
    ): ?string {
        if ($default === null) {
            return null;
        }

        if ($default instanceof NullDefaultExprNode) {
            return 'null';
        }

        if ($default instanceof EmptyArrayDefaultExprNode) {
            return '[]';
        }

        if ($default instanceof LiteralDefaultExprNode) {
            return $this->exportPhp($this->literalToPhp($default->literal));
        }

        if ($default instanceof ExpressionDefaultExprNode) {
            if ($default->expression instanceof LiteralNode) {
                return $this->exportPhp($this->literalToPhp($default->expression));
            }

            if ($default->expression instanceof EmptyArrayExprNode) {
                return '[]';
            }

            $warnings[] = new GenerationWarning(
                code: 'unsupported_default_expression',
                message: sprintf(
                    'Field "%s" in type "%s" has non-literal default expression; default was skipped.',
                    $fieldName,
                    $typeName,
                ),
                typeName: $typeName,
                fieldName: $fieldName,
            );

            return null;
        }

        $warnings[] = new GenerationWarning(
            code: 'unsupported_default_node',
            message: sprintf(
                'Field "%s" in type "%s" has unsupported default node "%s"; default was skipped.',
                $fieldName,
                $typeName,
                $default::class,
            ),
            typeName: $typeName,
            fieldName: $fieldName,
        );

        return null;
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function literalTypeAndValue(LiteralNode $literal): array
    {
        $value = $this->literalToPhp($literal);

        if (is_string($value)) {
            return ['string', $value];
        }

        if (is_int($value)) {
            return ['int', $value];
        }

        if (is_float($value)) {
            return ['float', $value];
        }

        if (is_bool($value)) {
            return ['bool', $value];
        }

        if ($value === null) {
            return ['mixed', null];
        }

        return ['mixed', $value];
    }

    private function literalToPhp(LiteralNode $literal): mixed
    {
        if ($literal instanceof StringLiteralNode) {
            return $literal->value;
        }

        if ($literal instanceof NumberLiteralNode) {
            return $literal->numericValue;
        }

        if ($literal instanceof BoolLiteralNode) {
            return $literal->value;
        }

        if ($literal instanceof NullLiteralNode) {
            return null;
        }

        if ($literal instanceof DurationLiteralNode) {
            return $literal->milliseconds;
        }

        return null;
    }

    private function normalizeRegexPattern(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '/^$/';
        }

        if (preg_match('/^([\/\~\#]).+\\1[imsxuADSUXJ]*$/', $raw) === 1) {
            return $raw;
        }

        return '/' . str_replace('/', '\\/', $raw) . '/u';
    }

    private function toPhpDateFormat(string $format): string
    {
        return strtr($format, [
            'YYYY' => 'Y',
            'MM' => 'm',
            'DD' => 'd',
            'HH' => 'H',
            'ii' => 'i',
            'SS' => 's',
        ]);
    }

    private function resolveConstraintTarget(string $targetType, ?string $fallbackBuiltinTarget): ?string
    {
        if ($this->builtinTypeInfo($targetType) !== null || $targetType === 'Array') {
            return $targetType;
        }

        if ($fallbackBuiltinTarget !== null) {
            return $fallbackBuiltinTarget;
        }

        return null;
    }

    /**
     * @return array{typeHint: string, docType: ?string, nullable: bool, constraints: string[]}|null
     */
    private function builtinTypeInfo(string $name): ?array
    {
        if (in_array($name, self::INTEGER_TYPES, true)) {
            return [
                'typeHint' => 'int',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ];
        }

        if (in_array($name, self::FLOAT_TYPES, true)) {
            return [
                'typeHint' => 'float',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ];
        }

        return match ($name) {
            'Decimal' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => [
                    'Assert\\Regex(pattern: "/^[+-]?\\d+(?:\\.\\d+)?$/")',
                ],
            ],
            'String' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ],
            'Url' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Url'],
            ],
            'Email' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Email'],
            ],
            'Uuid' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Uuid'],
            ],
            'Base64' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Regex(pattern: "/^[A-Za-z0-9+\\/]+={0,2}$/")'],
            ],
            'Date' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Date'],
            ],
            'DateTime' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\DateTime'],
            ],
            'Time' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Time'],
            ],
            'Duration' => [
                'typeHint' => 'int',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ],
            'Ip' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Ip'],
            ],
            'IpV4' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Ip(version: Assert\\Ip::V4)'],
            ],
            'IpV6' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\Ip(version: Assert\\Ip::V6)'],
            ],
            'Bool' => [
                'typeHint' => 'bool',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ],
            'True' => [
                'typeHint' => 'bool',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\IdenticalTo(value: true)'],
            ],
            'False' => [
                'typeHint' => 'bool',
                'docType' => null,
                'nullable' => false,
                'constraints' => ['Assert\\IdenticalTo(value: false)'],
            ],
            'Null' => [
                'typeHint' => 'mixed',
                'docType' => null,
                'nullable' => true,
                'constraints' => ['Assert\\IsNull'],
            ],
            'Binary' => [
                'typeHint' => 'string',
                'docType' => null,
                'nullable' => false,
                'constraints' => [],
            ],
            'Any' => [
                'typeHint' => 'mixed',
                'docType' => null,
                'nullable' => true,
                'constraints' => [],
            ],
            default => null,
        };
    }

    /**
     * @param array<int, array{
     *     sourceField: string,
     *     name: string,
     *     typeHint: string,
     *     docType: ?string,
     *     constraints: string[],
     *     defaultCode: ?string
     * }> $properties
     * @param string[] $classConstraints
     */
    private function renderClass(
        string $namespace,
        string $className,
        array $classConstraints,
        array $properties,
        bool $generateConstructor,
    ): string {
        $lines = [];

        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = 'namespace ' . $namespace . ';';
        $lines[] = '';
        $lines[] = 'use Symfony\\Component\\Validator\\Constraints as Assert;';
        $lines[] = '';

        foreach ($classConstraints as $constraint) {
            $lines[] = '#[' . $constraint . ']';
        }

        $lines[] = 'final class ' . $className;
        $lines[] = '{';

        foreach ($properties as $property) {
            if ($property['docType'] !== null) {
                $lines[] = '    /**';
                $lines[] = '     * @var ' . $property['docType'];
                $lines[] = '     */';
            }

            foreach ($property['constraints'] as $constraint) {
                $lines[] = '    #[' . $constraint . ']';
            }

            $lines[] = sprintf('    public %s $%s;', $property['typeHint'], $property['name']);
            $lines[] = '';
        }

        if ($generateConstructor) {
            $lines[] = '    public function __construct(';

            $paramLines = [];
            foreach ($properties as $property) {
                $param = '        ' . $property['typeHint'] . ' $' . $property['name'];
                if ($property['defaultCode'] !== null) {
                    $param .= ' = ' . $property['defaultCode'];
                }

                $paramLines[] = $param;
            }

            $lastIndex = count($paramLines) - 1;
            foreach ($paramLines as $index => $line) {
                $lines[] = $line . ($index < $lastIndex ? ',' : '');
            }

            $lines[] = '    ) {';

            foreach ($properties as $property) {
                $lines[] = sprintf('        $this->%s = $%s;', $property['name'], $property['name']);
            }

            $lines[] = '    }';
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private function buildTypeHint(string $baseTypeHint, bool $nullable): string
    {
        $baseTypeHint = trim($baseTypeHint);
        if ($baseTypeHint === '') {
            $baseTypeHint = 'mixed';
        }

        if (!$nullable || $baseTypeHint === 'mixed' || str_starts_with($baseTypeHint, '?')) {
            return $baseTypeHint;
        }

        return '?' . $baseTypeHint;
    }

    private function docTypeFromTypeHint(string $typeHint): string
    {
        if (str_starts_with($typeHint, '?')) {
            return substr($typeHint, 1) . '|null';
        }

        return $typeHint;
    }

    private function classTypeHint(string $namespace, string $className, string $currentNamespace): string
    {
        if ($namespace === $currentNamespace) {
            return $className;
        }

        return '\\' . $namespace . '\\' . $className;
    }

    private function normalizeClassName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $trimmed = preg_replace('/[^A-Za-z0-9_]/', '', $trimmed) ?? '';
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            $trimmed = 'Type' . $trimmed;
        }

        return ucfirst($trimmed);
    }

    private function normalizePropertyName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Za-z0-9_]/', '', $trimmed) ?? '';
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $normalized) !== 1) {
            $normalized = 'field' . ucfirst($normalized);
        }

        return lcfirst($normalized);
    }

    private function normalizeDir(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return '';
        }

        return rtrim($dir, '/');
    }

    private function buildFilePath(string $dir, string $fileName): string
    {
        if ($dir === '') {
            return $fileName;
        }

        return $dir . '/' . ltrim($fileName, '/');
    }

    private function exportPhp(mixed $value): string
    {
        return var_export($value, true);
    }

    /**
     * @return array<string, string>
     */
    private function flattenAnnotationTree(AnnotationTree $tree, array $path = []): array
    {
        $result = [];

        if ($tree->values !== []) {
            $key = implode('.', $path);
            if ($key !== '') {
                $last = $tree->values[array_key_last($tree->values)];
                $result[$key] = $last->value;
            }
        }

        foreach ($tree->children as $segment => $child) {
            $result += $this->flattenAnnotationTree($child, [...$path, $segment]);
        }

        return $result;
    }

    /**
     * @param array<string, string> $annotations
     * @return string[]
     */
    private function collectCustomSymfonyConstraints(array $annotations): array
    {
        $result = [];

        foreach ($annotations as $key => $value) {
            if ($key !== 'php.symfony.constraint' && !str_starts_with($key, 'php.symfony.constraint.')) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'true') {
                continue;
            }

            if (str_starts_with($trimmed, '#[') && str_ends_with($trimmed, ']')) {
                $trimmed = substr($trimmed, 2, -1);
            }

            if (!str_starts_with($trimmed, 'Assert\\') && !str_starts_with($trimmed, '\\')) {
                $trimmed = 'Assert\\' . $trimmed;
            }

            $result[] = $trimmed;
        }

        return $this->deduplicateConstraints($result);
    }

    /**
     * @param string[]|null $groups
     */
    private function withGroups(string $constraint, ?array $groups): string
    {
        if ($groups === null || $groups === []) {
            return $constraint;
        }

        if (str_contains($constraint, 'groups:')) {
            return $constraint;
        }

        $groupsCode = $this->exportPhp(array_values($groups));

        if (!str_contains($constraint, '(')) {
            return sprintf('%s(groups: %s)', $constraint, $groupsCode);
        }

        if (!str_ends_with($constraint, ')')) {
            return $constraint;
        }

        return substr($constraint, 0, -1) . ', groups: ' . $groupsCode . ')';
    }

    /**
     * @return string[]|null
     */
    private function parseCsv(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $trimmed),
        ), static fn (string $item): bool => $item !== ''));

        if ($parts === []) {
            return null;
        }

        return $parts;
    }

    private function parseBool(?string $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * @param string[] $constraints
     * @return string[]
     */
    private function deduplicateConstraints(array $constraints): array
    {
        $result = [];
        $seen = [];

        foreach ($constraints as $constraint) {
            $key = trim($constraint);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $key;
        }

        return $result;
    }

    /**
     * @param array<string, string> $annotations
     * @return GenerationWarning[]
     */
    private function collectUnknownTypeAnnotations(string $typeName, array $annotations): array
    {
        $warnings = [];
        foreach ($annotations as $key => $_value) {
            if (!str_starts_with($key, 'php.')) {
                continue;
            }

            if ($this->isAllowedTypeAnnotation($key)) {
                continue;
            }

            $warnings[] = new GenerationWarning(
                code: 'unknown_php_annotation',
                message: sprintf('Type "%s" has unrecognized PHP annotation "%s".', $typeName, $key),
                typeName: $typeName,
            );
        }

        return $warnings;
    }

    private function isAllowedTypeAnnotation(string $key): bool
    {
        if (in_array($key, [
            'php.codegen.namespace',
            'php.codegen.dir',
            'php.codegen.class',
            'php.codegen.file',
            'php.symfony.ignore',
            'php.symfony.validation.groups',
            'php.symfony.constraint',
        ], true)) {
            return true;
        }

        return str_starts_with($key, 'php.symfony.constraint.');
    }

    /**
     * @param array<string, string> $annotations
     * @return GenerationWarning[]
     */
    private function collectUnknownFieldAnnotations(string $typeName, string $fieldName, array $annotations): array
    {
        $warnings = [];

        foreach ($annotations as $key => $_value) {
            if (!str_starts_with($key, 'php.')) {
                continue;
            }

            if ($this->isAllowedFieldAnnotation($key)) {
                continue;
            }

            $warnings[] = new GenerationWarning(
                code: 'unknown_php_annotation',
                message: sprintf(
                    'Type "%s" field "%s" has unrecognized PHP annotation "%s".',
                    $typeName,
                    $fieldName,
                    $key,
                ),
                typeName: $typeName,
                fieldName: $fieldName,
            );
        }

        return $warnings;
    }

    private function isAllowedFieldAnnotation(string $key): bool
    {
        if (in_array($key, [
            'php.codegen.property',
            'php.symfony.ignore',
            'php.symfony.type',
            'php.symfony.not_blank',
            'php.symfony.validation.groups',
            'php.symfony.constraint',
        ], true)) {
            return true;
        }

        return str_starts_with($key, 'php.symfony.constraint.');
    }
}
