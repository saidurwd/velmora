<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use App\Modules\Platform\Enums\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Maps exceptions to RFC 9457 problem+json for API/JSON requests with stable codes; web requests use the bn/en error views. */
final class ExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return Problem::response(422, ErrorCode::ValidationFailed->value, __('errors.api.validation_failed'), null, ['errors' => $e->errors()]);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return self::wantsJson($request) ? self::coded(ErrorCode::Unauthenticated) : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return self::wantsJson($request) ? self::coded(ErrorCode::Forbidden) : null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return self::wantsJson($request) ? self::coded(ErrorCode::NotFound) : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return self::wantsJson($request) ? self::coded(ErrorCode::MethodNotAllowed) : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::coded(ErrorCode::RateLimited)->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! self::wantsJson($request) || $e instanceof HttpExceptionInterface || config('app.debug')) {
                return null;
            }

            report($e);

            return self::coded(ErrorCode::ServerError); // never leak internals outside local debugging
        });
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private static function coded(ErrorCode $code): JsonResponse
    {
        return Problem::response($code->status(), $code->value, __((string) $code->titleKey()));
    }
}
