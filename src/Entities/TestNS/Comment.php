<?php

declare(strict_types=1);

namespace App\Entities\TestNS;

use Symfony\Component\Validator\Constraints as Assert;

final class Comment
{
    #[Assert\NotNull]
    public int $authorId;

    #[Assert\NotNull]
    public string $text;

    #[Assert\DateTime]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    public ?string $createdAt;

    public function __construct(
        int $authorId,
        string $text,
        ?string $createdAt
    ) {
        $this->authorId = $authorId;
        $this->text = $text;
        $this->createdAt = $createdAt;
    }
}
