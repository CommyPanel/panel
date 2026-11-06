<?php

namespace App\Http\Controllers;

use App\Api\TokenAccess;
use App\Audit\AuditLogger;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A person's own API tokens (Settings > API tokens). Every signed-in user may make
 * tokens for their own account; a token carries only what that person may do, so
 * there is nothing to guard here beyond making and revoking them with the password.
 */
class ApiTokenController extends Controller
{
    private const MAX_TOKENS = 20;

    public function index(Request $request, TokenAccess $access): Response
    {
        $user = $request->user();
        $labels = array_map(fn (array $a) => $a['label'], TokenAccess::abilities());

        return Inertia::render('settings/tokens', [
            'tokens' => $user->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => array_values(array_filter(array_map(fn ($a) => $labels[$a] ?? null, $t->abilities ?? []))),
                'apps' => $access->appsOf($t),
                'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
            ])->all(),
            'can_create' => $access->allowedFor($user) !== [],
            'new_token' => session('new_token'),
            'api_url' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1',
        ]);
    }

    /**
     * The page for making a token: what it may do, which apps, how long it lasts.
     */
    public function create(Request $request, TokenAccess $access): Response
    {
        $user = $request->user();

        return Inertia::render('settings/token-create', [
            'abilities' => array_map(
                fn (string $key) => ['key' => $key, 'label' => TokenAccess::abilities()[$key]['label'], 'description' => TokenAccess::abilities()[$key]['description']],
                $access->allowedFor($user),
            ),
            'apps' => $user->can('apps.view') ? App::query()->whereNull('setup_step')->orderBy('name')->get(['slug', 'name'])->all() : [],
            'default_days' => TokenAccess::DEFAULT_DAYS,
        ]);
    }

    public function store(Request $request, TokenAccess $access, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $allowed = $access->allowedFor($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in($allowed)],
            'apps' => ['array', 'max:50'],
            'apps.*' => ['string', Rule::exists('apps', 'slug')],
            // a number of days, or "never"
            'days' => ['required', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'never' && (! is_numeric($value) || (int) $value < 1 || (int) $value > TokenAccess::MAX_DAYS || (string) (int) $value !== (string) $value)) {
                    $fail('Choose how long the token lasts.');
                }
            }],
        ], ['abilities.required' => 'Choose what the token may do.', 'abilities.*.in' => 'You cannot give a token something you may not do yourself.']);

        if ($user->tokens()->count() >= self::MAX_TOKENS) {
            return back()->withErrors(['name' => 'You have '.self::MAX_TOKENS.' tokens. Revoke one you no longer use first.']);
        }

        $never = $data['days'] === 'never';
        $token = $access->create($user, $data['name'], $data['abilities'], $data['apps'] ?? [], $never ? null : (int) $data['days']);

        $audit->record('token.created', $user, [
            'name' => $data['name'],
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'never_expires' => $never,
        ]);

        // Shown once, on the next page, and never again.
        return redirect()->route('settings.tokens')->with('new_token', ['name' => $data['name'], 'token' => $token->plainTextToken]);
    }

    public function destroy(Request $request, AuditLogger $audit, int $token): RedirectResponse
    {
        $found = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $name = $found->name;
        $found->delete();

        $audit->record('token.revoked', $request->user(), ['name' => $name]);

        return back()->with('status', "The token {$name} was revoked. Anything still using it stops working now.");
    }
}
