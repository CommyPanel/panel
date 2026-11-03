<?php

namespace App\Api;

use App\Auth\Permissions;
use App\Models\App;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What an API token may do. A token is made by a person for their own account, can
 * carry only abilities that person has, and can be limited to some apps. Whatever
 * it says, a request is still checked against what the person may do NOW, so
 * taking a permission away from a user takes it from their tokens too.
 *
 * Abilities are strings on the token: `apps:read`, `apps:deploy`, and one
 * `app:<slug>` for each app the token is limited to (none means every app the
 * person may use).
 */
class TokenAccess
{
    public const READ = 'apps:read';

    public const DEPLOY = 'apps:deploy';

    /** The most a token may live, and what it gets when nothing else is said. */
    public const MAX_DAYS = 365;

    public const DEFAULT_DAYS = 90;

    /**
     * What a person may put on a token: each ability with the permission it needs.
     *
     * @return array<string, array{label: string, description: string, permission: string}>
     */
    public static function abilities(): array
    {
        return [
            self::READ => ['label' => 'Read apps and deployments', 'description' => 'List apps, see their status and read how a deployment went.', 'permission' => Permissions::APPS_VIEW],
            self::DEPLOY => ['label' => 'Start deployments', 'description' => 'Deploy an app, for example from a CI pipeline after the tests pass.', 'permission' => Permissions::APPS_DEPLOY],
        ];
    }

    /**
     * The abilities this person may give a token.
     *
     * @return list<string>
     */
    public function allowedFor(User $user): array
    {
        return array_keys(array_filter(self::abilities(), fn (array $a) => $user->can($a['permission'])));
    }

    /**
     * @param  list<string>  $abilities  of self::abilities()
     * @param  list<string>  $appSlugs  limit the token to these apps; none = all
     */
    public function create(User $user, string $name, array $abilities, array $appSlugs, int $days): NewAccessToken
    {
        $allowed = $this->allowedFor($user);
        $abilities = array_values(array_unique(array_intersect($abilities, $allowed)));
        $days = max(1, min(self::MAX_DAYS, $days));

        return $user->createToken(
            $name,
            [...$abilities, ...array_map(fn (string $slug) => 'app:'.$slug, array_values(array_unique($appSlugs)))],
            Carbon::now()->addDays($days),
        );
    }

    /**
     * Whether the token (of the signed-in request) is limited to some apps and this is not one.
     */
    public function allowsApp(PersonalAccessToken $token, App $app): bool
    {
        $limited = array_values(array_filter($token->abilities ?? [], fn ($a) => str_starts_with((string) $a, 'app:')));

        return $limited === [] || in_array('app:'.$app->slug, $limited, true);
    }

    /**
     * The slugs a token is limited to; empty when it is not.
     *
     * @return list<string>
     */
    public function appsOf(PersonalAccessToken $token): array
    {
        return array_values(array_map(
            fn ($a) => substr((string) $a, 4),
            array_filter($token->abilities ?? [], fn ($a) => str_starts_with((string) $a, 'app:')),
        ));
    }
}
