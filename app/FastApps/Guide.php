<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;

/**
 * What a person should know about a ready-made app before choosing it, shown under the choice on the
 * create page: what CommyPanel does by itself, what is left for the person (the first account, how
 * other apps use it) and how it is upgraded. The summary is the recipe's own description.
 */
final class Guide
{
    /**
     * @return array{automatic: list<string>, after: list<string>, upgrade: list<string>}
     */
    public static function for(FastApp $app): array
    {
        $id = $app->id();

        $automatic = [(string) __('Runs the official image at a version CommyPanel pins.')];

        if ($id !== 'uptime-kuma') {
            $automatic[] = (string) __('Generates its password or key and keeps it encrypted. You see it with Show access details on the app\'s page.');
        }

        if ($app->volumePaths() !== []) {
            $automatic[] = (string) __('Keeps its data in a persistent volume that survives deployments and rollbacks.');
        }

        $specific = match ($id) {
            'meilisearch' => (string) __('Turns on the master key, so nothing answers without it.'),
            'typesense' => (string) __('Requires the API key on every request.'),
            'grafana' => (string) __('Creates the admin user with the generated password.'),
            'elasticsearch' => (string) __('Starts one node with security on and a user called elastic.'),
            'kibana' => (string) __('Links to your Elasticsearch app over a private network and creates its own kibana_system user.'),
            'uptime-kuma' => (string) __('Chooses SQLite, so there is no database to set up.'),
            'mailpit' => (string) __('Protects the web inbox with a login and keeps the SMTP port (1025) on the private network.'),
            'gotenberg' => (string) __('Turns on basic authentication for its API.'),
            'redis' => (string) __('Sets a password and writes it into a config file only Redis can read.'),
            'n8n' => (string) __('Generates the key that encrypts the credentials n8n stores.'),
            'garage' => (string) __('Sets up a one-node cluster, an access key and a bucket called main.'),
            default => null,
        };

        if ($specific !== null) {
            $automatic[] = $specific;
        }

        $after = match ($id) {
            'grafana' => [(string) __('Sign in as admin with the generated password, then add your data sources.')],
            'kibana' => [(string) __('Sign in with the elastic user and the password of your Elasticsearch app.')],
            'uptime-kuma' => [(string) __('The first person to open Uptime Kuma creates its admin account. Open it as soon as it is running, before anyone else can.')],
            'n8n' => [(string) __('The first person to open n8n creates its owner account. Open it as soon as it is running, before anyone else can.')],
            'redis' => [(string) __('It has no web interface and takes no domain. Apps use it through a link: add one on their Links tab.')],
            'mailpit' => [(string) __('Read the caught email in the web inbox. Point your app\'s mail settings at the SMTP variables its link adds.')],
            'garage' => [(string) __('It has no web interface. Use any S3 client with the access key, secret key and region its link adds.')],
            default => [(string) __('To use it from your app, link it on the app\'s Links tab: its address and keys arrive as environment variables.')],
        };

        $pinned = (string) __('CommyPanel pins the version. When a newer CommyPanel release pins a newer one, press Deploy on the app to move to it.');

        if ($id === 'gotenberg') {
            $upgrade = [(string) __('CommyPanel pins the version. When a newer CommyPanel release pins a newer one, press Deploy on the app. It keeps no data, so there is nothing to back up.')];
        } else {
            $upgrade = [$pinned];

            if ($app->deployStrategy() === DeployStrategy::StopFirst) {
                $upgrade[] = (string) __('It cannot run twice on the same data, so a deploy stops the old version first and the app is unavailable for a short while. If the new version does not start, the old one is started again.');
            }

            $upgrade[] = (string) __('Back up its volume first (Volumes tab): a rollback brings back the old version but not the old data.');
        }

        return ['automatic' => $automatic, 'after' => $after, 'upgrade' => $upgrade];
    }
}
