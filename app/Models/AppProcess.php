<?php

namespace App\Models;

use App\Deploy\CommandLine;
use App\Enums\ProcessKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One extra process of an app (a scheduler, a queue worker, Horizon ...): a container of the same
 * image as the web one, with its own command. The web process is the app itself and has no row.
 * Only enabled rows run, and only from the next deployment (or "Apply changes").
 *
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property ProcessKind $kind
 * @property string|null $command
 * @property bool $enabled
 * @property int $replicas
 * @property string $overlap
 * @property int|null $memory_limit_mb
 * @property int $stop_grace_seconds
 * @property string|null $detected_by
 * @property int $sort
 */
class AppProcess extends Model
{
    /** A new release may run beside the old one. */
    public const OVERLAP = 'overlap';

    /** The old one must be stopped before the new one starts (a scheduler, Horizon). */
    public const STOP_FIRST = 'stop_first';

    public const MAX_COMMAND_LENGTH = 1000;

    public const MAX_ARGUMENTS = 64;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Changing a process changes what the next release runs, like a variable or a volume does.
        $touch = fn (self $m) => App::query()->whereKey($m->app_id)->update(['config_changed_at' => now()]);
        static::saved($touch);
        static::deleted($touch);
    }

    protected function casts(): array
    {
        return [
            'kind' => ProcessKind::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** The command to run: the person's, else the usual one of its kind. */
    public function effectiveCommand(): ?string
    {
        $command = trim((string) $this->command);

        return $command !== '' ? $command : $this->kind->defaultCommand();
    }

    /**
     * The command as an argument list (no shell).
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException with a sentence fit to show
     */
    public function argv(): array
    {
        return self::split((string) $this->effectiveCommand());
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function split(string $command): array
    {
        if (strlen($command) > self::MAX_COMMAND_LENGTH || str_contains($command, "\0")) {
            throw new InvalidArgumentException(__('The command is too long or holds an invalid character.'));
        }

        $argv = CommandLine::split($command);

        if ($argv === []) {
            throw new InvalidArgumentException(__('Enter a command.'));
        }

        if (count($argv) > self::MAX_ARGUMENTS) {
            throw new InvalidArgumentException(__('The command has too many arguments.'));
        }

        return $argv;
    }

    public function isSingleton(): bool
    {
        return $this->overlap === self::STOP_FIRST;
    }
}
