<?php

declare(strict_types=1);

namespace App\Modules\Cms\DTOs;

/** One visible block of the home page, already in the visitor's language. `body` is sanitised HTML (text blocks only). */
final readonly class HomeSectionView
{
    public function __construct(public string $type, public ?string $title, public ?string $body, public ?string $collectionRef) {}
}
