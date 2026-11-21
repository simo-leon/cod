<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON responses for the COD System import API.
 *
 * A client that calls the import endpoint without an `Accept` header gets
 * a 302 redirect (instead of a 422 JSON body) when validation fails,
 * because Laravel only renders validation errors as JSON for requests
 * that expect JSON. Setting the header here makes every response from
 * this route JSON, whatever the client sends.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
