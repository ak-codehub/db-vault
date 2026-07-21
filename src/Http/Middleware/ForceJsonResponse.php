<?php

declare(strict_types=1);

namespace DbVault\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the vault's JSON API always answers with JSON — never an HTML
 * redirect — for validation and auth failures, regardless of how the HOST
 * application has configured its exception handler.
 *
 * Laravel decides whether to return 422 JSON or a 302 redirect for a
 * ValidationException based on $request->expectsJson() AND any host-level
 * `shouldRenderJsonWhen()` rule. A common host rule is
 * `fn ($r) => $r->is('api/*')`, which does NOT match the vault's
 * "{path}/api/*" mount — so validation errors would redirect and the SPA
 * could not read the field messages. Rather than depend on host config, we:
 *
 *  1. mark the request as JSON-expecting (Accept: application/json), and
 *  2. catch ValidationException / AuthenticationException here and emit the
 *     canonical JSON envelope ourselves.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        try {
            return $next($request);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        } catch (AuthenticationException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 401);
        }
    }
}
