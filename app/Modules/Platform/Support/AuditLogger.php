<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Writes append-only audit rows (BD-29). Before/after are redacted; callers need not remember to. */
final class AuditLogger
{
    public function __construct(private readonly RequestContext $context, private readonly Clock $clock) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $action,
        Model|string|null $subject = null,
        array $before = [],
        array $after = [],
        ?Actor $actor = null,
        string|int|null $subjectId = null,
    ): void {
        $actor ??= $this->context->actor;

        [$type, $id] = match (true) {
            $subject instanceof Model => [$subject->getMorphClass(), (string) ($subject->getAttribute('public_id') ?? $subject->getKey())],
            is_string($subject) => [$subject, $subjectId === null ? null : (string) $subjectId],
            default => [null, null],
        };

        DB::table('audit_events')->insert([
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'before' => $before === [] ? null : json_encode(Redactor::array($before), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => $after === [] ? null : json_encode(Redactor::array($after), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip' => $this->context->ip,
            'user_agent' => $this->context->userAgent === null ? null : mb_substr($this->context->userAgent, 0, 255),
            'request_id' => $this->context->requestId,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
