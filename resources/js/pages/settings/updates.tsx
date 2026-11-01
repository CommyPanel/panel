import { router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    CircleArrowUp,
    ExternalLink,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import UpdateController from '@/actions/App/Http/Controllers/UpdateController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CommandBox } from '@/components/ui/command-box';
import AppLayout from '@/layouts/app-layout';
import { formatDate, timeAgo } from '@/lib/format';
import { useCan } from '@/lib/permissions';
import type { ReleaseSummary, UpdateInfo } from '@/types/update';

function Release({
    release,
    defaultOpen = true,
}: {
    release: ReleaseSummary;
    defaultOpen?: boolean;
}) {
    return (
        <details open={defaultOpen} className="group space-y-3">
            <summary className="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 rounded-field py-1 marker:content-none">
                <h3 className="flex items-center gap-2 text-base font-semibold">
                    <ChevronRight
                        className="size-4 shrink-0 transition-transform group-open:rotate-90"
                        aria-hidden
                    />
                    {release.name}
                    <span className="badge-soft badge-neutral badge badge-sm">
                        {release.version}
                    </span>
                </h3>
                <span className="flex items-center gap-3 text-sm text-base-content/70">
                    {release.published_at && (
                        <time dateTime={release.published_at}>
                            {formatDate(release.published_at)}
                        </time>
                    )}
                    <a
                        href={release.url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="link inline-flex items-center gap-1 link-primary"
                    >
                        On GitHub
                        <ExternalLink className="size-3.5" aria-hidden />
                    </a>
                </span>
            </summary>
            {/* Rendered from Markdown on the server with raw HTML, unsafe links and images removed. */}
            <div
                className="release-notes rounded-field border border-base-300 p-4"
                dangerouslySetInnerHTML={{ __html: release.notes_html }}
            />
        </details>
    );
}

function Status({ update }: { update: UpdateInfo }) {
    if (!update.supported) {
        return (
            <Alert variant="info">
                This is a development build ({update.current}), so there is no
                released version to compare it with and update checks are off.
            </Alert>
        );
    }

    if (update.available) {
        return (
            <div className="flex items-start gap-3">
                <CircleArrowUp
                    className="mt-0.5 size-6 shrink-0 text-primary"
                    aria-hidden
                />
                <div className="space-y-1">
                    <p className="text-lg font-semibold">
                        Version {update.latest} is available
                    </p>
                    <p className="text-sm text-base-content/70">
                        You are running {update.current}. Read the release notes
                        below, then follow the steps to upgrade.
                    </p>
                </div>
            </div>
        );
    }

    if (update.checked_at === null) {
        return (
            <p className="text-sm text-base-content/70">
                Nothing has been checked yet. CommyPanel looks for new versions
                when someone signs in, or you can check now.
            </p>
        );
    }

    return (
        <div className="flex items-start gap-3">
            <ShieldCheck
                className="mt-0.5 size-6 shrink-0 text-success"
                aria-hidden
            />
            <div className="space-y-1">
                <p className="text-lg font-semibold">
                    You are running the latest version
                </p>
                <p className="text-sm text-base-content/70">
                    CommyPanel {update.current} is the newest release.
                </p>
            </div>
        </div>
    );
}

export default function Updates({ update }: { update: UpdateInfo }) {
    const { errors } = usePage().props;
    const can = useCan();
    const canManage = can('server.manage');
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);

    const checkNow = () =>
        router.post(
            UpdateController.check(),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    const toggle = (enabled: boolean) =>
        router.put(
            UpdateController.update(),
            { enabled },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );

    const command =
        update.available && update.latest
            ? `sudo commypanel upgrade --version ${update.latest}`
            : 'sudo commypanel upgrade';

    return (
        <AppLayout title="Updates">
            <PageHeader
                title="Updates"
                description="Which version of CommyPanel this server runs, and how to move to a newer one."
            />

            {errors.update && <Alert variant="error">{errors.update}</Alert>}

            <div className="grid items-start gap-6 xl:grid-cols-3">
                <div className="min-w-0 space-y-6 xl:col-span-2">
                    <Card
                        actions={
                            canManage &&
                            !update.forced_off &&
                            update.supported && (
                                <Button
                                    variant="soft"
                                    size="sm"
                                    onClick={checkNow}
                                    loading={checking}
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                    Check now
                                </Button>
                            )
                        }
                        title="Status"
                    >
                        <Status update={update} />
                        {update.error && (
                            <Alert variant="warning">
                                The last check did not work: {update.error}{' '}
                                <a
                                    href={update.releases_url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="link"
                                >
                                    See the releases on GitHub
                                </a>
                                .
                            </Alert>
                        )}
                        {update.checked_at && (
                            <p className="text-xs text-base-content/70">
                                Last checked {timeAgo(update.checked_at)}.
                            </p>
                        )}
                    </Card>

                    <Card
                        title="Why keep CommyPanel up to date"
                        description="It controls your server, so running an old version is a risk."
                    >
                        <p className="text-sm">
                            New releases bring security patches, bug fixes and
                            new features. Fixes only reach you when you upgrade,
                            and a known problem that stays unpatched is the
                            easiest way into a server. Upgrade soon after a
                            release, and read what changed first.
                        </p>
                    </Card>

                    <Card
                        title="How to upgrade"
                        description="Upgrading is done on the server itself, not from this page."
                    >
                        <ol className="list-decimal space-y-3 pl-5 text-sm">
                            <li>
                                <strong>Read the release notes</strong> below,
                                especially anything under “Before you upgrade”.
                                Some releases need you to do something
                                afterwards, such as granting a new permission to
                                your own roles.
                            </li>
                            <li>
                                <strong>
                                    Check that no deployment is running.
                                </strong>{' '}
                                The upgrade restarts the agent, which interrupts
                                a build in progress.
                            </li>
                            <li>
                                <strong>Take a backup or snapshot</strong> of
                                the server if you can. The upgrade backs up
                                CommyPanel&apos;s own databases first, but not
                                your apps&apos; data.
                            </li>
                            <li className="space-y-2">
                                <span>
                                    <strong>
                                        Connect to the server over SSH
                                    </strong>{' '}
                                    and run:
                                </span>
                                <CommandBox command={command} />
                                <span className="block text-base-content/70">
                                    It tells you what is about to happen and
                                    asks you to confirm before anything is
                                    changed.
                                </span>
                            </li>
                            <li>
                                <strong>Wait for it to finish,</strong> then
                                reload this page. It shows the new version, and
                                the update badge in the top bar disappears.
                            </li>
                        </ol>
                    </Card>

                    <Card
                        title="What to expect"
                        description="What happens to your server while it upgrades."
                    >
                        <ul className="list-disc space-y-3 pl-5 text-sm">
                            <li>
                                <strong>Your apps keep running.</strong> Docker
                                and your containers are not restarted, and nginx
                                is only reloaded gracefully, so your apps and
                                domains stay up.
                            </li>
                            <li>
                                <strong>
                                    CommyPanel is briefly unavailable.
                                </strong>{' '}
                                Its background services (the agent, the queue
                                workers and the scheduler) restart and the panel
                                switches to the new release. If this page shows
                                an error for a short while, wait and reload; you
                                may be asked to sign in again.
                            </li>
                            <li>
                                <strong>A failed upgrade goes back.</strong> The
                                installer waits up to 45 seconds for the new
                                version to answer. If it does not, the previous
                                version is started again. Database changes made
                                by the new version are kept, so read the
                                installer&apos;s output, fix the cause and run
                                the upgrade again. The newest backups of the
                                panel&apos;s databases are in{' '}
                                <code>/var/lib/commypanel/backups</code>.
                            </li>
                        </ul>
                    </Card>

                    {update.newer.length > 0 && (
                        <Card
                            title="What changed"
                            description={
                                update.newer.length > 1
                                    ? `Release notes for the ${update.newer.length} releases since ${update.current}, newest first.`
                                    : `Release notes for ${update.newer[0].version}.`
                            }
                        >
                            <div className="space-y-8">
                                {update.newer.map((release, index) => (
                                    <Release
                                        key={release.version}
                                        release={release}
                                        defaultOpen={index === 0}
                                    />
                                ))}
                            </div>
                        </Card>
                    )}

                    {update.newer.length === 0 && update.installed && (
                        <Card
                            title="Release notes for this version"
                            description="You are up to date. This is what the version you run brought."
                        >
                            <Release release={update.installed} />
                        </Card>
                    )}
                </div>

                <div className="min-w-0 space-y-6">
                    <Card title="Versions">
                        <dl className="divide-y divide-base-300 text-sm">
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">
                                    Running
                                </dt>
                                <dd className="font-medium">
                                    {update.current}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">
                                    Latest release
                                </dt>
                                <dd className="font-medium">
                                    {update.latest ?? '-'}
                                </dd>
                            </div>
                        </dl>
                        <a
                            href={update.releases_url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="link inline-flex items-center gap-1 text-sm link-primary"
                        >
                            All releases on GitHub
                            <ExternalLink className="size-3.5" aria-hidden />
                        </a>
                    </Card>

                    <Card
                        title="Automatic checks"
                        description="How CommyPanel finds out about new versions."
                    >
                        <label className="flex cursor-pointer items-start gap-3">
                            <input
                                type="checkbox"
                                className="switch mt-0.5 switch-primary"
                                checked={update.enabled}
                                disabled={
                                    !canManage || update.forced_off || saving
                                }
                                onChange={(e) => toggle(e.target.checked)}
                            />
                            <span className="text-sm">
                                Look for new versions when someone signs in
                            </span>
                        </label>
                        {update.forced_off && (
                            <p className="text-sm text-base-content/70">
                                Switched off in the server&apos;s configuration
                                (<code>COMMYPANEL_UPDATE_CHECK=false</code>), so
                                nothing is checked, not even by hand.
                            </p>
                        )}
                        <p className="text-sm text-base-content/70">
                            When someone signs in, CommyPanel asks GitHub for
                            this project&apos;s list of releases, in the
                            background and at most once every 6 hours. GitHub
                            sees this server&apos;s address and the CommyPanel
                            version number, and nothing else is sent. To stop
                            all checks, set{' '}
                            <code>COMMYPANEL_UPDATE_CHECK=false</code> in the
                            server&apos;s environment file (
                            <code>/etc/commypanel/env</code>).
                        </p>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
