<?php

namespace App\Alerts;

/**
 * Everything the panel can send a message about. The value is the key saved on a target and
 * sent as `event` in the generic JSON format.
 */
enum AlertEvent: string
{
    case DeploymentFailed = 'deployment.failed';
    case DeploymentRolledBack = 'deployment.rolled_back';
    case DeploymentSucceeded = 'deployment.succeeded';
    case AppDown = 'app.down';
    case AppRecovered = 'app.recovered';
    case CertificateFailed = 'certificate.failed';
    case CertificateExpiring = 'certificate.expiring';
    case DiskWarning = 'disk.warning';
    case DiskCritical = 'disk.critical';
    case DiskRecovered = 'disk.recovered';
    case BackupFailed = 'backup.failed';
    case DatabaseStopped = 'database.stopped';
    case DatabaseRecovered = 'database.recovered';

    public const PROBLEM = 'problem';

    public const OK = 'ok';

    public const INFO = 'info';

    public function label(): string
    {
        return match ($this) {
            self::DeploymentFailed => __('A deployment failed'),
            self::DeploymentRolledBack => __('A release was rolled back automatically'),
            self::DeploymentSucceeded => __('A deployment succeeded'),
            self::AppDown => __('An app stopped answering'),
            self::AppRecovered => __('An app is answering again'),
            self::CertificateFailed => __('A certificate could not be issued or renewed'),
            self::CertificateExpiring => __('A certificate is about to expire'),
            self::DiskWarning => __('The disk is getting full'),
            self::DiskCritical => __('The disk is almost full'),
            self::DiskRecovered => __('The disk has room again'),
            self::BackupFailed => __('A backup failed'),
            self::DatabaseStopped => __('A database server stopped'),
            self::DatabaseRecovered => __('A database server is running again'),
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::DeploymentFailed, self::DeploymentRolledBack, self::DeploymentSucceeded => __('Deployments'),
            self::AppDown, self::AppRecovered => __('Apps'),
            self::CertificateFailed, self::CertificateExpiring => __('Certificates'),
            self::DiskWarning, self::DiskCritical, self::DiskRecovered => __('Server'),
            self::BackupFailed => __('Backups'),
            self::DatabaseStopped, self::DatabaseRecovered => __('Databases'),
        };
    }

    /**
     * Whether a new target listens for it from the start: problems yes, good news no.
     */
    public function defaultOn(): bool
    {
        return ! in_array($this, [self::DeploymentSucceeded, self::AppRecovered, self::DiskRecovered, self::DatabaseRecovered], true);
    }

    /**
     * @return self::PROBLEM|self::OK
     */
    public function severity(): string
    {
        return match ($this) {
            self::DeploymentSucceeded, self::AppRecovered, self::DiskRecovered, self::DatabaseRecovered => self::OK,
            default => self::PROBLEM,
        };
    }

    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_values(array_map(
            fn (self $e): string => $e->value,
            array_filter(self::cases(), fn (self $e): bool => $e->defaultOn()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $e): string => $e->value, self::cases());
    }
}
