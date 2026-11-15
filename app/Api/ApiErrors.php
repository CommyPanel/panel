<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One error shape for the whole API, so a client reads one key: every JSON error has `error`
 * (a sentence). Laravel's own errors (401, 404, 422, 429, 500) carry a `message`; it is copied
 * into `error` and kept where it was. A 422 also keeps its `errors` object (field => sentences).
 */
final class ApiErrors
{
    public static function normalize(Response $response): Response
    {
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || isset($data['error'])) {
            return $response;
        }

        $message = is_string($data['message'] ?? null) && $data['message'] !== ''
            ? $data['message']
            : (Response::$statusTexts[$response->getStatusCode()] ?? 'Error');

        $response->setData(['error' => $message, ...$data]);

        return $response;
    }
}
