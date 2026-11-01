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

    public const APPS_DEACTIVATE = 'apps.deactivate';

    public const APPS_DELETE = 'apps.delete';

    public const ENV_MANAGE = 'env.manage';

    public const DOMAINS_VIEW = 'domains.view';

    public const DOMAINS_MANAGE = 'domains.manage';

    public const DOMAINS_SETTINGS = 'domains.settings';

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
            self::APPS_DEACTIVATE => ['group' => 'Apps', 'label' => 'Deactivate and activate apps', 'description' => 'Take an app offline without deleting it, and bring it back.'],
            self::APPS_DELETE => ['group' => 'Apps', 'label' => 'Delete apps', 'description' => 'Permanently delete an app with its releases, images, domains, certificate and variables.'],
            self::ENV_MANAGE => ['group' => 'Apps', 'label' => 'Manage environment variables', 'description' => 'Add, replace, import and remove an app\'s environment variables. These often hold secrets such as database passwords.'],
            self::DOMAINS_VIEW => ['group' => 'Domains', 'label' => 'View domains', 'description' => 'See every domain with its DNS and HTTPS state.'],
            self::DOMAINS_MANAGE => ['group' => 'Domains', 'label' => 'Manage domains and certificates', 'description' => 'Add domains to apps, set redirects, re-check DNS, remove domains and request certificates.'],
            self::DOMAINS_SETTINGS => ['group' => 'Domains', 'label' => 'Change HTTPS and server address', 'description' => 'Accept the Let\'s Encrypt terms, set the server\'s public address and the panel\'s own domain.'],
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
