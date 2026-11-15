<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\TokenAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the calling token is: its name, what it may do and until when. Clients use it to check a
 * token when it is saved. It needs no ability beyond being a valid token, and says nothing about
 * the token's secret or the owner beyond a name.
 */
class TokenController extends ApiController
{
    public function show(Request $request, TokenAccess $access): JsonResponse
    {
        $token = $this->token($request);
        $apps = $access->appsOf($token);

        return response()->json(['data' => [
            'name' => $token->name,
            'abilities' => array_values(array_filter($token->abilities ?? [], fn ($a) => ! str_starts_with((string) $a, 'app:'))),
            'apps' => $apps === [] ? null : $apps,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'owner' => $request->user()->name,
        ]]);
    }
}
