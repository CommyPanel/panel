<?php

namespace App\Http\Middleware;

use App\Auth\Permissions;
use App\Auth\UserAccess;
use App\Host\HostHealth;
use App\I18n\Locales;
use App\I18n\Translations;
use App\Updates\UpdateChecker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'locales' => collect(Locales::available())->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])->values()->all(),
            // Every sentence of the person's language, sent once and remembered by the browser for that language.
            'translations' => Inertia::once(fn () => Translations::for(app()->getLocale()))->as('translations-'.app()->getLocale()),
            // Lazy, so partial reloads that do not ask for it never contact the agent.
            'hostHealth' => fn () => $request->user() === null ? null : app(HostHealth::class)->status(),
            // Read from what is already remembered; this never contacts GitHub.
            'update' => fn () => $request->user()?->can(Permissions::SERVER_VIEW)
                ? app(UpdateChecker::class)->status()->summary()
                : null,
            'flash' => [
                'status' => $request->session()->get('status'),
                'warning' => $request->session()->get('warning'),
            ],
            'auth' => [
                'user' => $request->user(),
                'isSuper' => $request->user()?->isSuper() ?? false,
                'permissions' => $request->user() === null ? [] : app(UserAccess::class)->permissionsOf($request->user()),
                'twoFactor' => [
                    'enabled' => $request->user()?->two_factor_confirmed_at !== null,
                    'pending' => $request->user() !== null
                        && $request->user()->two_factor_secret !== null
                        && $request->user()->two_factor_confirmed_at === null,
                ],
            ],
        ];
    }
}
