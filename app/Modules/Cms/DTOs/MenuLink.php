<?php

declare(strict_types=1);

namespace App\Modules\Cms\DTOs;

/** One entry of a storefront menu, already in the visitor's language. A group has children and no address. */
final readonly class MenuLink
{
    /** @param  list<MenuLink>  $children */
    public function __construct(public string $label, public ?string $url, public array $children = []) {}

    public function isGroup(): bool
    {
        return $this->url === null;
    }
}
