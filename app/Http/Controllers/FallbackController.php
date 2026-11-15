<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Answers a request that matches no route, from inside the web group so the error page has a
 * session and the panel's look. It gives what Laravel would have given: 405 (with the allowed
 * methods) when the address exists for another method, 404 otherwise.
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request): never
    {
        $allowed = [];

        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            if ($method === $request->getMethod()) {
                continue;
            }

            foreach (Route::getRoutes()->get($method) as $route) {
                /** @var RouteDefinition $route */
                if (! $route->isFallback && $route->matches($request, false)) {
                    $allowed[] = $method;

                    break;
                }
            }
        }

        abort_if($allowed !== [], 405, headers: ['Allow' => implode(', ', $allowed)]);
        abort(404);
    }
}
