<?php

declare(strict_types=1);

namespace App\Entities;

use Symfony\Component\Validator\Constraints as Assert;

final class Post
{
    #[Assert\NotNull]
    public int $id;

    #[Assert\NotNull]
    #[Assert\Length(min: 5)]
    #[Assert\Length(max: 255)]
    public string $title;

    #[Assert\NotNull]
    #[Assert\Length(max: 65535)]
    public string $text;

    #[Assert\NotNull]
    #[Assert\DateTime]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    public string $createdAt;

    #[Assert\DateTime]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    #[Assert\GreaterThanOrEqual(propertyPath: 'createdAt')]
    public ?string $modifiedAt;

    #[Assert\Length(min: 10)]
    #[Assert\Length(max: 255)]
    public ?string $rejectReason;

    /**
     * @var list<string>
     */
    #[Assert\NotNull]
    #[Assert\Type(type: "array")]
    #[Assert\Count(max: 10)]
    #[Assert\All(constraints: [new Assert\Length(max: 15)])]
    public array $tags;

    /**
     * @var list<array<string, mixed>>
     */
    #[Assert\NotNull]
    #[Assert\Type(type: "array")]
    #[Assert\All(constraints: [new Assert\Type(type: "array")])]
    public array $meta;

    /**
     * @var list<\App\Entities\TestNS\Comment>
     */
    #[Assert\NotNull]
    #[Assert\Type(type: "array")]
    #[Assert\All(constraints: [new Assert\Valid])]
    public array $comments;

    /**
     * @var array<string, string>
     */
    #[Assert\NotNull]
    #[Assert\Type(type: "array")]
    #[Assert\All(constraints: [new Assert\Length(max: 255)])]
    public array $customVariables;

    #[Assert\NotNull]
    #[Assert\DateTime]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    #[Assert\LessThanOrEqual(propertyPath: 'activeTo')]
    public string $activeFrom;

    #[Assert\DateTime]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    #[Assert\GreaterThanOrEqual(propertyPath: 'activeFrom')]
    public ?string $activeTo;

    public ?string $internalNote;

    public function __construct(
        int $id,
        string $title,
        string $text,
        string $createdAt,
        ?string $modifiedAt = null,
        ?string $rejectReason = null,
        array $tags,
        array $meta = [],
        array $comments,
        array $customVariables,
        string $activeFrom,
        ?string $activeTo = null,
        ?string $internalNote = null
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->text = $text;
        $this->createdAt = $createdAt;
        $this->modifiedAt = $modifiedAt;
        $this->rejectReason = $rejectReason;
        $this->tags = $tags;
        $this->meta = $meta;
        $this->comments = $comments;
        $this->customVariables = $customVariables;
        $this->activeFrom = $activeFrom;
        $this->activeTo = $activeTo;
        $this->internalNote = $internalNote;
    }
}
