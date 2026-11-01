<?php

namespace App\Providers;

use App\Audit\AuditEventSubscriber;
use App\Audit\AuditLogger;
use App\Deploy\Health\AssumeHealthyChecker;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Health\HttpHealthChecker;
use App\Deploy\Source\CompositeSourceFetcher;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\NginxTrafficSwitcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Dns\DnsResolver;
use App\Dns\SystemDnsResolver;
use App\Host\Drivers\AgentHostDriver;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\CheckForUpdates;
use App\Models\User;
use App\Updates\UpdateChecker;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AuditLogger::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(SourceFetcher::class, CompositeSourceFetcher::class);
        // With the fake host driver no container ever listens, so assume health.
        $this->app->bind(HealthChecker::class, fn () => config('commypanel.host_driver') === 'fake'
            ? new AssumeHealthyChecker
            : new HttpHealthChecker);
        $this->app->bind(TrafficSwitcher::class, NginxTrafficSwitcher::class);

        $this->app->singleton(HostDriver::class, fn (): HostDriver => match (config('commypanel.host_driver')) {
            'fake' => new FakeHostDriver,
            'agent' => $this->agentDriver(),
            default => throw new RuntimeException('Unknown host driver ['.config('commypanel.host_driver').'].'),
        });
    }

    private function agentDriver(): HostDriver
    {
        if (PHP_OS_FAMILY !== 'Linux' && ! config('commypanel.agent_allow_non_linux')) {
            throw new RuntimeException('The agent host driver only runs on Linux. Use HOST_DRIVER=fake for development.');
        }

        return new AgentHostDriver((string) config('commypanel.agent_socket'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The super user passes every check; everyone else needs the permission.
        Gate::before(fn (User $user) => $user->isSuper() ? true : null);

        $this->configureDefaults();

        Event::subscribe(AuditEventSubscriber::class);

        // Looking for a newer release happens in the background after someone
        // signs in, and only when it is due (see UpdateChecker). Whatever goes
        // wrong here (a broken cache, a full queue) is logged and must never
        // stop anyone from signing in.
        Event::listen(Login::class, function (): void {
            try {
                if (app(UpdateChecker::class)->shouldCheck()) {
                    CheckForUpdates::dispatch();
                }
            } catch (Throwable $e) {
                report($e);
            }
        });

        // Push webhooks are public; a sender cannot make the panel do much
        // without the secret, but it should not be able to keep it busy either.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
