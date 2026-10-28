<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** Per-request facts shared by logging, auditing and Actions' callers. Scoped: one instance per request/job. */
final class RequestContext
{
    public function __construct(
        public string $requestId,
        public Actor $actor,
        public string $locale,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public function withActor(Actor $actor): self
    {
        $clone = clone $this;
        $clone->actor = $actor;

        return $clone;
    }
}
