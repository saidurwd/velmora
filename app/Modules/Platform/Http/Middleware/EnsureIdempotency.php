<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Http\Problem;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key handling for critical writes (ADR-017, BD §7/§12).
 *  - same key + same request  -> stored response replayed (Idempotency-Replayed: true)
 *  - same key + different one -> 409 idempotency.conflict
 *  - same key still running   -> 409 idempotency.in_progress
 * Usage: ->middleware('idempotent') (optional header) or ->middleware('idempotent:required').
 */
class EnsureIdempotency
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');

        if ($key === '') {
            return $mode === 'required'
                ? Problem::response(400, 'idempotency.key_required', 'Idempotency-Key header is required.')
                : $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return Problem::response(400, 'idempotency.key_invalid', 'Idempotency-Key must be 8–100 URL-safe characters.');
        }

        $scope = $request->method().' '.$request->path().'|'.$this->actorKey($request);
        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent()."\n".json_encode($request->post()));
        $now = $this->clock->now();

        try {
            DB::table('idempotency_keys')->insert([
                'scope' => mb_substr($scope, 0, 191),
                'key' => $key,
                'request_hash' => $hash,
                'status' => 'processing',
                'expires_at' => $now->addHours((int) config('velmora.idempotency.ttl_hours', 24))->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            return $this->existing(mb_substr($scope, 0, 191), $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->release($scope, $key); // let the client retry after a crash

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($scope, $key);

            return $response;
        }

        $body = (string) $response->getContent();
        $store = strlen($body) <= (int) config('velmora.idempotency.max_body_bytes', 1_000_000);

        DB::table('idempotency_keys')->where('scope', mb_substr($scope, 0, 191))->where('key', $key)->update([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_headers' => json_encode(['Content-Type' => $response->headers->get('Content-Type')], JSON_THROW_ON_ERROR),
            'response_body' => $store ? $body : null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);

        return $response;
    }

    private function existing(string $scope, string $key, string $hash): Response
    {
        $row = DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->first();

        if ($row === null) {
            return Problem::response(409, 'idempotency.in_progress', 'A request with this Idempotency-Key is in progress.');
        }

        if (! hash_equals((string) $row->request_hash, $hash)) {
            return Problem::response(409, 'idempotency.conflict', 'This Idempotency-Key was already used with a different request.');
        }

        if ($row->status !== 'completed') {
            return Problem::response(409, 'idempotency.in_progress', 'A request with this Idempotency-Key is in progress.');
        }

        /** @var array<string, string|null> $headers */
        $headers = json_decode((string) $row->response_headers, true) ?: [];

        return new Response(
            (string) $row->response_body,
            (int) $row->response_status,
            array_filter($headers) + ['Idempotency-Replayed' => 'true'],
        );
    }

    private function release(string $scope, string $key): void
    {
        DB::table('idempotency_keys')->where('scope', mb_substr($scope, 0, 191))->where('key', $key)->delete();
    }

    private function actorKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? 'u'.$user->getAuthIdentifier() : 'ip'.sha1((string) $request->ip());
    }
}
