<?php

namespace App\Audit;

/**
 * How much an audit entry matters, so the log can show it at a glance: `danger` is a very high
 * impact action (exposing something to the internet, deleting data or access), `warning` is a
 * sensitive one (secrets seen, access or settings changed, something failed), everything else is
 * routine. Unknown actions are routine, so a new action never looks scarier than it is by accident.
 */
final class Severity
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public const DANGER_ACTIONS = [
        'database.remote_opened', 'database.remote_changed',
        'database_server.deleted', 'database.dropped', 'app.deleted', 'user.deleted',
        'auth.two_factor_disabled', 'admin.reset', 'app.volume_data_deleted',
        'database.restore_replaced', 'volume.restored',
    ];

    public const WARNING_ACTIONS = [
        'database.remote_failed', 'database.credentials_viewed', 'database.password_changed', 'database.restore_started', 'database.restore_replace_failed', 'volume.restore_failed', 'backup.remote_downloaded',
        'database.tool_started', 'database.tool_opened', 'database.backup_downloaded',
        'backup.downloaded', 'backup.remote_changed', 'backup.remote_removed', 'backup.deleted', 'volume.backup_downloaded',
        'app.terminal_opened', 'app.nginx_updated', 'app.volume_file_downloaded', 'app.volume_removed', 'app.git_token_saved', 'app.env_removed',
        'token.created', 'user.created', 'user.updated', 'role.created', 'role.updated', 'role.deleted',
        'auth.password_changed', 'auth.two_factor_failed', 'auth.login_failed', 'auth.recovery_codes_generated', 'auth.recovery_code_used',
        'https.disabled', 'panel.domain_removed', 'panel.domain_set', 'github.disconnected', 'github.connected', 'notifications.removed',
        'deploy.failed', 'deploy.auto_rolled_back', 'deploy.auto_rollback_failed', 'tls.failed', 'docker.restart_failed',
    ];

    public static function of(string $action): string
    {
        return match (true) {
            in_array($action, self::DANGER_ACTIONS, true) => self::DANGER,
            in_array($action, self::WARNING_ACTIONS, true) => self::WARNING,
            default => self::INFO,
        };
    }
}
