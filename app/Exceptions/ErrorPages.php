<?php

namespace App\Exceptions;

use Carbon\CarbonInterval;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Error pages that look like the rest of the panel and arrive as Inertia responses, so a failed
 * visit never ends in the browser's grey overlay with Laravel's plain page. Machines (JSON, the
 * API, webhooks without a session) keep the plain answers they expect.
 */
final class ErrorPages
{
    /** The statuses that get a page of their own. Anything else keeps Laravel's answer. */
    public const PAGES = [400, 401, 403, 404, 405, 408, 412, 413, 500, 502, 503, 504];

    /** Whether this request is a person using the panel in a browser. */
    public static function forPeople(Request $request): bool
    {
        return ! $request->expectsJson() && ! $request->is('api/*');
    }

    /** Replaces the plain response of a failed request with a page, or hands the plain one back. */
    public static function respond(SymfonyResponse $response, Throwable $e, Request $request): SymfonyResponse
    {
        $status = $response->getStatusCode();

        if (! self::forPeople($request) || ! $request->hasSession()) {
            return $response;
        }

        // A form that sat open too long: go back with a note, instead of an error page.
        if ($status === 419) {
            return back()->with('warning', __('The page expired. Please try again.'));
        }

        // Developers want the trace of a crash, not a friendly page.
        if ($status === 500 && config('app.debug')) {
            return $response;
        }

        if (! in_array($status, self::PAGES, true)) {
            return $response;
        }

        try {
            $page = Inertia::render('error', ['status' => $status])->toResponse($request);
        } catch (Throwable) {
            return $response; // the page itself cannot be drawn (no database, no build): keep the plain one
        }

        $page->setStatusCode($status);

        foreach (['Allow', 'Retry-After'] as $header) {
            if ($response->headers->has($header)) {
                $page->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $page;
    }

    /**
     * Too many attempts, for a person in the panel: back to the page with the time to wait, as a
     * message on the form that was used (sign in, two-factor code) or as a notice anywhere else.
     */
    public static function throttled(ThrottleRequestsException $e, Request $request): ?RedirectResponse
    {
        if (! self::forPeople($request) || ! $request->hasSession()) {
            return null;
        }

        $message = __('Too many attempts. Try again in :time.', ['time' => self::wait((int) ($e->getHeaders()['Retry-After'] ?? 60))]);

        $field = match ($request->route()?->getName()) {
            'login.store' => 'email',
            'two-factor.login' => 'code',
            default => null,
        };

        $back = back();

        return $field === null ? $back->with('warning', $message) : $back->withErrors([$field => $message])->withInput($request->except('password'));
    }

    /** "5 minutes", "30 seconds": rounded up to whole minutes from a minute on, in the person's language. */
    public static function wait(int $seconds): string
    {
        $seconds = max(1, $seconds);
        $interval = $seconds >= 60 ? CarbonInterval::minutes((int) ceil($seconds / 60)) : CarbonInterval::seconds($seconds);

        return $interval->locale(app()->getLocale())->forHumans(['parts' => 1]);
    }
}
