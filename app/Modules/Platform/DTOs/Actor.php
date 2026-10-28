<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

use App\Modules\Platform\Enums\ActorType;

/**
 * Who is performing an action. Actions receive this explicitly and never read session/request/auth globals,
 * so Web, Admin and API behave identically.
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?int $id = null,
        public ?string $publicId = null,
        public ?string $label = null,
    ) {}

    public static function system(string $label = 'system'): self
    {
        return new self(ActorType::System, label: $label);
    }

    public static function guest(?string $label = null): self
    {
        return new self(ActorType::Guest, label: $label);
    }

    public static function provider(string $provider): self
    {
        return new self(ActorType::Provider, label: $provider);
    }

    public function isStaff(): bool
    {
        return $this->type === ActorType::Staff;
    }
}
