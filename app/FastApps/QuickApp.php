<?php

namespace App\FastApps;

use App\Models\App;
use Illuminate\Support\Str;

/** Defaults shared by the recipes: most need no choices, no warnings and only the usual health timeout. */
abstract class QuickApp implements FastApp
{
    /** The pinned official image, with a plain version tag. */
    abstract protected function image(): string;

    public function fields(): array
    {
        return [];
    }

    public function requires(): ?string
    {
        return null;
    }

    public function private(): bool
    {
        return false;
    }

    public function pingHealth(): bool
    {
        return false;
    }

    public function linkEnvironment(App $target, string $prefix): array
    {
        return [];
    }

    public function healthTimeout(): ?int
    {
        return null;
    }

    public function memoryLimitMb(): ?int
    {
        return null;
    }

    public function warnings(): array
    {
        return [];
    }

    public function oneTimeVariables(): array
    {
        return [];
    }

    public function release(): string
    {
        return $this->image();
    }

    public function files(App $app): array
    {
        return ['Dockerfile' => $this->dockerfile()];
    }

    /** The Dockerfile: the pinned image plus the recipe's fixed settings. */
    protected function dockerfile(): string
    {
        return "FROM {$this->image()}\n";
    }

    /** A secret that stays the same for the life of the app: letters and digits only, so it is safe in any config. */
    protected function secret(int $length = 32): string
    {
        return Str::random($length);
    }

    /**
     * A secret in hexadecimal, for the tools that insist on it.
     *
     * @param  positive-int  $bytes
     */
    protected function hex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    protected function variable(App $app, string $key): string
    {
        return $app->environment()[$key] ?? '';
    }

    /**
     * @return array{label: string, value: string, secret: bool}
     */
    protected function row(string $label, string $value, bool $secret = false): array
    {
        return ['label' => $label, 'value' => $value, 'secret' => $secret];
    }

    /** The address another app on a link answers to. */
    protected function internalAddress(App $app): string
    {
        return "http://{$app->slug}:{$this->containerPort()}";
    }
}
