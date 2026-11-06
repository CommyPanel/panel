<?php

namespace App\Auth;

/**
 * Every permission the panel knows, with the words shown on the Permissions
 * page. Routes are protected with `can:<name>` middleware; the list is synced
 * into the database by SyncRolesAndPermissions, so adding one here is enough.
 */
final class Permissions
{
    public const APPS_VIEW = 'apps.view';

    public const APPS_CREATE = 'apps.create';

    public const APPS_UPDATE = 'apps.update';

    public const APPS_DEPLOY = 'apps.deploy';

    public const APPS_CONTROL = 'apps.control';

    public const APPS_NGINX = 'apps.nginx';

    public const APPS_LOGS = 'apps.logs';

    public const APPS_VOLUMES = 'apps.volumes';

    public const APPS_TERMINAL = 'apps.terminal';

    public const APPS_DEACTIVATE = 'apps.deactivate';

    public const APPS_DELETE = 'apps.delete';

    public const ENV_MANAGE = 'env.manage';

    public const DOMAINS_VIEW = 'domains.view';

    public const DOMAINS_MANAGE = 'domains.manage';

    public const DOMAINS_SETTINGS = 'domains.settings';

    public const DATABASES_VIEW = 'databases.view';

    public const DATABASES_MANAGE = 'databases.manage';

    public const DATABASES_CREDENTIALS = 'databases.credentials';

    public const DATABASES_TOOLS = 'databases.tools';

    public const DATABASES_REMOTE = 'databases.remote';

    public const APPS_LINKS = 'apps.links';

    public const BACKUPS_VIEW = 'backups.view';

    public const BACKUPS_MANAGE = 'backups.manage';

    public const TOKENS_MANAGE = 'tokens.manage';

    public const GITHUB_MANAGE = 'github.manage';

    public const NOTIFICATIONS_MANAGE = 'notifications.manage';

    public const SERVER_VIEW = 'server.view';

    public const SERVER_MANAGE = 'server.manage';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const PERMISSIONS_VIEW = 'permissions.view';

    public const AUDIT_VIEW = 'audit.view';

    /**
     * @return array<string, array{group: string, label: string, description: string}>
     */
    public static function definitions(): array
    {
        return [
            self::APPS_VIEW => ['group' => 'Apps', 'label' => 'View apps', 'description' => 'See the list of apps, their releases, logs, settings and domains. Environment variable values are never shown.'],
            self::APPS_CREATE => ['group' => 'Apps', 'label' => 'Create apps', 'description' => 'Create new apps with the setup wizard, including connecting a repository or uploading a project.'],
            self::APPS_UPDATE => ['group' => 'Apps', 'label' => 'Change app settings', 'description' => 'Change an app\'s name, source, port, health check and resource limits; generate its deploy key; upload a new project archive.'],
            self::APPS_DEPLOY => ['group' => 'Apps', 'label' => 'Deploy and roll back', 'description' => 'Start deployments and roll an app back to an earlier release. Deploying runs the app\'s code on this server.'],
            self::APPS_CONTROL => ['group' => 'Apps', 'label' => 'Start, stop and restart apps', 'description' => 'Stop an app\'s running container, start it again and restart it. Its domains stay configured; visitors see an error while it is stopped.'],
            self::APPS_NGINX => ['group' => 'Apps', 'label' => 'Edit nginx directives', 'description' => 'Add custom nginx directives to an app\'s site: upload limits, timeouts, headers, redirects and extra locations. Only a list of safe directives is accepted and nginx tests the result before it is applied, but the app\'s traffic goes through it.'],
            self::APPS_LOGS => ['group' => 'Apps', 'label' => 'Read app logs', 'description' => 'Read and download the output of an app\'s running container. Apps often print secrets, such as tokens or personal data, into their logs, and a download is recorded in the audit log.'],
            self::APPS_VOLUMES => ['group' => 'Apps', 'label' => 'Browse volume files', 'description' => 'See how much space an app\'s persistent volumes use, look at their files and download them. Volumes often hold databases, uploads and other private data, and a download is recorded in the audit log.'],
            self::APPS_TERMINAL => ['group' => 'Apps', 'label' => 'Open a terminal in an app', 'description' => 'Run commands in a shell inside an app\'s running container, with the app\'s environment variables (often secrets such as database passwords) and its volumes. Asks for the password unless it was confirmed recently, and opening one is recorded in the audit log. This is close to full control of the app.'],
            self::APPS_DEACTIVATE => ['group' => 'Apps', 'label' => 'Deactivate and activate apps', 'description' => 'Take an app offline without deleting it, and bring it back.'],
            self::APPS_DELETE => ['group' => 'Apps', 'label' => 'Delete apps', 'description' => 'Permanently delete an app with its releases, images, domains, certificate and variables.'],
            self::ENV_MANAGE => ['group' => 'Apps', 'label' => 'Manage environment variables', 'description' => 'Add, replace, import and remove an app\'s environment variables. These often hold secrets such as database passwords.'],
            self::DOMAINS_VIEW => ['group' => 'Domains', 'label' => 'View domains', 'description' => 'See every domain with its DNS and HTTPS state.'],
            self::DOMAINS_MANAGE => ['group' => 'Domains', 'label' => 'Manage domains and certificates', 'description' => 'Add domains to apps, set redirects, re-check DNS, remove domains and request certificates.'],
            self::DOMAINS_SETTINGS => ['group' => 'Domains', 'label' => 'Change HTTPS and server address', 'description' => 'Accept the Let\'s Encrypt terms, set the server\'s public address and the panel\'s own domain.'],
            self::DATABASES_VIEW => ['group' => 'Databases', 'label' => 'View databases', 'description' => 'See the database servers, the databases on them and which apps use them. Passwords and connection strings are not shown.'],
            self::DATABASES_MANAGE => ['group' => 'Databases', 'label' => 'Manage databases', 'description' => 'Create, start, stop and delete database servers, and create, drop and change the password of databases on them. Deleting a server deletes the data of every database on it.'],
            self::DATABASES_CREDENTIALS => ['group' => 'Databases', 'label' => 'See database passwords', 'description' => 'Show a database\'s password and connection string. Asks for the password unless it was confirmed recently, and every time it is shown is recorded in the audit log.'],
            self::DATABASES_REMOTE => ['group' => 'Databases', 'label' => 'Open databases to the internet', 'description' => 'Make a database server reachable from chosen IP addresses on the internet for a limited time (or without end), and close it again. Anyone who holds a database password and comes from an allowed address can then connect. Asks for the password unless it was confirmed recently, and every change is recorded in the audit log as a high-risk action.'],
            self::DATABASES_TOOLS => ['group' => 'Databases', 'label' => 'Open databases in phpMyAdmin or pgAdmin', 'description' => 'Open a database in phpMyAdmin (MySQL, MariaDB) or pgAdmin (PostgreSQL), logged in as that database\'s own user: read, change and delete its data, run SQL and import or export dumps. Asks for the password unless it was confirmed recently, and every opening is recorded in the audit log. Treat it like the database\'s password.'],
            self::APPS_LINKS => ['group' => 'Apps', 'label' => 'Link apps to databases and other apps', 'description' => 'Let an app reach a database, or another app, over a private network made for that link. A linked app can use the database it is given, and its connection details are added to its environment variables.'],
            self::BACKUPS_VIEW => ['group' => 'Server', 'label' => 'View backups', 'description' => 'See the list of the panel\'s backups, when they were made and how big they are.'],
            self::BACKUPS_MANAGE => ['group' => 'Server', 'label' => 'Manage backups', 'description' => 'Take a backup now, download one and delete one. A backup holds everything in the panel\'s database, including the encrypted secrets of apps and databases, so downloading asks for the password and is recorded in the audit log.'],
            self::TOKENS_MANAGE => ['group' => 'Access', 'label' => 'Manage own API tokens', 'description' => 'Create and revoke your own API tokens for scripts and CI. A token can only do what you may do yourself (read apps, start deployments), optionally limited to some apps, and stops working if you lose that permission. Creating and revoking ask for your password and are recorded in the audit log.'],
            self::GITHUB_MANAGE => ['group' => 'Server', 'label' => 'Connect GitHub', 'description' => 'Create the panel\'s GitHub App, install it on an account and disconnect it. The app can read the code of the repositories you give it access to on GitHub, and anyone who may create apps can then deploy those repositories.'],
            self::NOTIFICATIONS_MANAGE => ['group' => 'Server', 'label' => 'Manage deployment messages', 'description' => 'Choose where the result of deployments is sent (Slack, Discord or any webhook), send a test message and switch it off. The address is stored encrypted and never shown again; the messages hold the app, commit and error, never logs or secrets.'],
            self::SERVER_VIEW => ['group' => 'Server', 'label' => 'View the server', 'description' => 'See host resources, service states, Docker and nginx status.'],
            self::SERVER_MANAGE => ['group' => 'Server', 'label' => 'Manage Docker and nginx', 'description' => 'Reload nginx, test its configuration, remove unused images and read container logs.'],
            self::USERS_VIEW => ['group' => 'Users', 'label' => 'View users', 'description' => 'See the list of user accounts and their roles.'],
            self::USERS_CREATE => ['group' => 'Users', 'label' => 'Create users', 'description' => 'Create accounts. A user can only be given a role that grants no more than the creator has.'],
            self::USERS_UPDATE => ['group' => 'Users', 'label' => 'Update users', 'description' => 'Change a user\'s name, email, password and role. Not the super user, and not users with more access than you have.'],
            self::USERS_DELETE => ['group' => 'Users', 'label' => 'Delete users', 'description' => 'Delete accounts. Not the super user, yourself, or users with more access than you have.'],
            self::ROLES_VIEW => ['group' => 'Access', 'label' => 'View roles', 'description' => 'See the roles and which permissions they grant.'],
            self::ROLES_MANAGE => ['group' => 'Access', 'label' => 'Manage roles', 'description' => 'Create, edit and delete roles. A role can only grant permissions you hold yourself.'],
            self::PERMISSIONS_VIEW => ['group' => 'Access', 'label' => 'View permissions', 'description' => 'See the list of permissions and what each one allows.'],
            self::AUDIT_VIEW => ['group' => 'Access', 'label' => 'View the audit log', 'description' => 'Read the record of who did what on this panel.'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }
}
