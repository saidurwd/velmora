<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Api;

use App\Modules\Engagement\Actions\CloseOwnSupportTicket;
use App\Modules\Engagement\Actions\OpenSupportTicket;
use App\Modules\Engagement\Actions\ReplyToSupportTicket;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\EngagementProblems;
use App\Modules\Engagement\Http\Requests\Api\SupportApiRequest;
use App\Modules\Engagement\Services\SupportReader;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The customer's conversations with the shop (words only in this version). Always their own; somebody else's ticket is "not found". */
class SupportController
{
    private const TICKET = 'array{id: string, reference: string, subject: string, category: string, status: string, last_message_at: string|null, created_at: string}';

    /**
     * My tickets
     *
     * Most recently active first.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{tickets: list<'.self::TICKET.'>, categories: list<string>}}')]
    public function index(Request $request, SupportReader $reader): JsonResponse
    {
        return response()->json(['data' => [
            'tickets' => array_map(fn ($t): array => $this->ticket($t), $reader->mine($this->userId($request))),
            'categories' => OpenSupportTicket::CATEGORIES,
        ]]);
    }

    /**
     * Open a ticket
     *
     * `category` is one of the codes from the list call. `order` (an order `id` of mine) is optional. 5 or more characters of text.
     */
    #[ResponseDoc(status: 201, type: 'array{data: array{id: string}}')]
    public function store(SupportApiRequest $request, OpenSupportTicket $open): JsonResponse
    {
        try {
            $id = $open->handle($this->userId($request), app()->getLocale(), (string) $request->validated('subject'), (string) $request->validated('category'), $request->validated('order'), (string) $request->validated('body'));
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e, 'support.errors');
        }

        return response()->json(['data' => ['id' => $id]], 201);
    }

    /**
     * One ticket with its messages
     *
     * `mine` is true for my own messages. Notes the shop keeps for itself are never shown.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{ticket: '.self::TICKET.', order: array{id: string, number: string}|null, messages: list<array{id: string, mine: bool, body: string, at: string}>}}')]
    public function show(Request $request, string $id, SupportReader $reader): JsonResponse
    {
        $thread = $reader->thread($this->userId($request), $id) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => [
            'ticket' => $this->ticket($thread['ticket']),
            'order' => $thread['order'] === null ? null : ['id' => $thread['order']['public_id'], 'number' => $thread['order']['number']],
            'messages' => array_map(fn (array $m): array => ['id' => $m['id'], 'mine' => $m['mine'], 'body' => $m['body'], 'at' => CarbonImmutable::parse((string) $m['at'], 'UTC')->toIso8601String()], $thread['messages']),
        ]]);
    }

    /**
     * Reply
     *
     * @status 204
     */
    public function reply(SupportApiRequest $request, string $id, ReplyToSupportTicket $reply): Response
    {
        try {
            $reply->handle($this->userId($request), $id, (string) $request->validated('body'));
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e, 'support.errors');
        }

        return response()->noContent();
    }

    /**
     * Close a ticket
     *
     * @status 204
     */
    public function close(Request $request, string $id, CloseOwnSupportTicket $close): Response
    {
        try {
            $close->handle($this->userId($request), $id);
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e, 'support.errors');
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function ticket(\stdClass $t): array
    {
        return [
            'id' => (string) $t->public_id, 'reference' => (string) $t->reference, 'subject' => (string) $t->subject, 'category' => (string) $t->category, 'status' => (string) $t->status,
            'last_message_at' => $t->last_message_at === null ? null : CarbonImmutable::parse((string) $t->last_message_at, 'UTC')->toIso8601String(), 'created_at' => CarbonImmutable::parse((string) $t->created_at, 'UTC')->toIso8601String(),
        ];
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
