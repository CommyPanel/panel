<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\SyncRolesAndPermissions;
use App\I18n\Locales;
use App\Models\SetupToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(string $token): Response
    {
        $this->authorizeSetup($token);

        return Inertia::render('auth/setup', ['token' => $token]);
    }

    public function store(Request $request, string $token, AuditLogger $audit, SyncRolesAndPermissions $sync): RedirectResponse
    {
        $this->authorizeSetup($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::available()))],
        ]);

        $user = DB::transaction(function () use ($token, $data): User {
            $setupToken = SetupToken::findUsable($token);

            abort_if($setupToken === null || User::query()->exists(), 404);

            $setupToken->forceFill(['used_at' => now()])->save();

            $user = User::query()->create(Arr::except($data, ['locale']) + ['email_verified_at' => now()]);
            $user->forceFill(['locale' => Locales::resolve($data['locale'] ?? app()->getLocale())])->save();
            // The first account is the super user. The flag is never mass-assignable.
            $user->forceFill(['is_super' => true])->save();

            return $user;
        });

        // First run: create the permissions and the "admin" role (nobody holds it yet).
        $sync();

        $audit->record('setup.completed', $user);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->route('welcome');
    }

    private function authorizeSetup(string $token): void
    {
        abort_if(User::query()->exists() || SetupToken::findUsable($token) === null, 404);
    }
}
