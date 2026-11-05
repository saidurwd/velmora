<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

/**
 * What happened when one line was put in a cart on someone's behalf (reorder, "move to cart"). `added` is how many units went in (0 when skipped);
 * `reason` is a key under cart::cart.errors when the line could not go in whole, with its `params`.
 */
final readonly class CartFillResult
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public string $status, public int $requested, public int $added, public ?string $reason = null, public array $params = []) {}

    public function wasAdded(): bool
    {
        return $this->added > 0;
    }
}
