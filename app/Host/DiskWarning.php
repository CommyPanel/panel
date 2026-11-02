<?php

namespace App\Host;

/**
 * How full the disk under the panel's data is, as one of three levels. Backups,
 * database data, images and volumes all live there, and a full disk breaks
 * builds, backups and databases at once.
 */
final readonly class DiskWarning
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const CRITICAL = 'critical';

    /**
     * @param  self::*  $level
     */
    public function __construct(
        public string $level,
        public int $usedPercent,
        public int $freeBytes,
        public int $totalBytes,
    ) {}

    /**
     * Null when the agent did not say how big the disk is (an older agent).
     */
    public static function of(int $totalBytes, int $freeBytes): ?self
    {
        if ($totalBytes <= 0) {
            return null;
        }

        $used = (int) round(100 * (1 - min($freeBytes, $totalBytes) / $totalBytes));
        $level = match (true) {
            $used >= (int) config('commypanel.disk.critical_percent') => self::CRITICAL,
            $used >= (int) config('commypanel.disk.warn_percent') => self::WARN,
            default => self::OK,
        };

        return new self($level, $used, $freeBytes, $totalBytes);
    }

    /**
     * @return array{level: string, used_percent: int, free_bytes: int, total_bytes: int}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'used_percent' => $this->usedPercent, 'free_bytes' => $this->freeBytes, 'total_bytes' => $this->totalBytes];
    }
}
