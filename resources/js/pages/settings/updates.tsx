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
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { formatDate, timeAgo } from '@/lib/format';
import { useCan } from '@/lib/permissions';
import { Trans } from '@/lib/trans';
import type { ReleaseSummary, UpdateInfo } from '@/types/update';

function Release({
    release,
    defaultOpen = true,
}: {
    release: ReleaseSummary;
    defaultOpen?: boolean;
}) {
    const { t } = useT();

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
                        {t('On GitHub')}
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
    const { t } = useT();

    if (!update.supported) {
        return (
            <Alert variant="info">
                {t(
                    'This is a development build (:version), so there is no released version to compare it with and update checks are off.',
                    { version: update.current },
                )}
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
                        {t('Version :version is available', {
                            version: update.latest ?? '',
                        })}
                    </p>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'You are running :version. Read the release notes below, then follow the steps to upgrade.',
                            { version: update.current },
                        )}
                    </p>
                </div>
            </div>
        );
    }

    if (update.checked_at === null) {
        return (
            <p className="text-sm text-base-content/70">
                {t(
                    'Nothing has been checked yet. CommyPanel looks for new versions when someone signs in, or you can check now.',
                )}
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
                    {t('You are running the latest version')}
                </p>
                <p className="text-sm text-base-content/70">
                    {t('CommyPanel :version is the newest release.', {
                        version: update.current,
                    })}
                </p>
            </div>
        </div>
    );
}

export default function Updates({ update }: { update: UpdateInfo }) {
    const { t, tn } = useT();
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
        <AppLayout title={t('Updates')}>
            <PageHeader
                title={t('Updates')}
                description={t(
                    'Which version of CommyPanel this server runs, and how to move to a newer one.',
                )}
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
                                    {t('Check now')}
                                </Button>
                            )
                        }
                        title={t('Status')}
                    >
                        <Status update={update} />
                        {update.error && (
                            <Alert variant="warning">
                                <Trans
                                    text="The last check did not work: :error <link>See the releases on GitHub</link>."
                                    components={{
                                        link: (
                                            <a
                                                href={update.releases_url}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="link"
                                            />
                                        ),
                                    }}
                                    values={{ error: update.error ?? '' }}
                                />
                            </Alert>
                        )}
                        {update.checked_at && (
                            <p className="text-xs text-base-content/70">
                                {t('Last checked :when.', {
                                    when: timeAgo(update.checked_at),
                                })}
                            </p>
                        )}
                    </Card>

                    <Card
                        title={t('Why keep CommyPanel up to date')}
                        description={t(
                            'It controls your server, so running an old version is a risk.',
                        )}
                    >
                        <p className="text-sm">
                            {t(
                                'New releases bring security patches, bug fixes and new features. Fixes only reach you when you upgrade, and a known problem that stays unpatched is the easiest way into a server. Upgrade soon after a release, and read what changed first.',
                            )}
                        </p>
                    </Card>

                    <Card
                        title={t('How to upgrade')}
                        description={t(
                            'Upgrading is done on the server itself, not from this page.',
                        )}
                    >
                        <ol className="list-decimal space-y-3 pl-5 text-sm">
                            <li>
                                <Trans
                                    text="<b>Read the release notes</b> below, especially anything under “Before you upgrade”. Some releases need you to do something afterwards, such as granting a new permission to your own roles."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                            <li>
                                <Trans
                                    text="<b>Check that no deployment is running.</b> The upgrade restarts the agent, which interrupts a build in progress."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                            <li>
                                <Trans
                                    text="<b>Take a backup or snapshot</b> of the server if you can. The upgrade backs up CommyPanel's own databases first, but not your apps' data."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                            <li className="space-y-2">
                                <span>
                                    <Trans
                                        text="<b>Connect to the server over SSH</b> and run:"
                                        components={{ b: <strong /> }}
                                    />
                                </span>
                                <CommandBox command={command} />
                                <span className="block text-base-content/70">
                                    {t(
                                        'It tells you what is about to happen and asks you to confirm before anything is changed.',
                                    )}
                                </span>
                            </li>
                            <li>
                                <Trans
                                    text="<b>Wait for it to finish,</b> then reload this page. It shows the new version, and the update badge in the top bar disappears."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                        </ol>
                    </Card>

                    <Card
                        title={t('What to expect')}
                        description={t(
                            'What happens to your server while it upgrades.',
                        )}
                    >
                        <ul className="list-disc space-y-3 pl-5 text-sm">
                            <li>
                                <Trans
                                    text="<b>Your apps keep running.</b> Docker and your containers are not restarted, and nginx is only reloaded gracefully, so your apps and domains stay up."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                            <li>
                                <Trans
                                    text="<b>CommyPanel is briefly unavailable.</b> Its background services (the agent, the queue workers and the scheduler) restart and the panel switches to the new release. If this page shows an error for a short while, wait and reload; you may be asked to sign in again."
                                    components={{ b: <strong /> }}
                                />
                            </li>
                            <li>
                                <Trans
                                    text="<b>A failed upgrade goes back.</b> The installer waits up to 45 seconds for the new version to answer. If it does not, the previous version is started again. Database changes made by the new version are kept, so read the installer's output, fix the cause and run the upgrade again. The newest backups of the panel's databases are in <code>/var/lib/commypanel/backups</code>."
                                    components={{
                                        b: <strong />,
                                        code: <code />,
                                    }}
                                />
                            </li>
                        </ul>
                    </Card>

                    {update.newer.length > 0 && (
                        <Card
                            title={t('What changed')}
                            description={
                                update.newer.length > 1
                                    ? tn(
                                          'one::Release notes for the :count release since :version, newest first.|other::Release notes for the :count releases since :version, newest first.',
                                          update.newer.length,
                                          { version: update.current },
                                      )
                                    : t('Release notes for :version.', {
                                          version: update.newer[0].version,
                                      })
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
                            title={t('Release notes for this version')}
                            description={t(
                                'You are up to date. This is what the version you run brought.',
                            )}
                        >
                            <Release release={update.installed} />
                        </Card>
                    )}
                </div>

                <div className="min-w-0 space-y-6">
                    <Card title={t('Versions')}>
                        <dl className="divide-y divide-base-300 text-sm">
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">
                                    {t('Running')}
                                </dt>
                                <dd className="font-medium">
                                    {update.current}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">
                                    {t('Latest release')}
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
                            {t('All releases on GitHub')}
                            <ExternalLink className="size-3.5" aria-hidden />
                        </a>
                    </Card>

                    <Card
                        title={t('Automatic checks')}
                        description={t(
                            'How CommyPanel finds out about new versions.',
                        )}
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
                                {t(
                                    'Look for new versions when someone signs in',
                                )}
                            </span>
                        </label>
                        {update.forced_off && (
                            <p className="text-sm text-base-content/70">
                                <Trans
                                    text="Switched off in the server's configuration (<code>COMMYPANEL_UPDATE_CHECK=false</code>), so nothing is checked, not even by hand."
                                    components={{ code: <code /> }}
                                />
                            </p>
                        )}
                        <p className="text-sm text-base-content/70">
                            <Trans
                                text="When someone signs in, CommyPanel asks GitHub for this project's list of releases, in the background and at most once every 6 hours. GitHub sees this server's address and the CommyPanel version number, and nothing else is sent. To stop all checks, set <code>COMMYPANEL_UPDATE_CHECK=false</code> in the server's environment file (<code2>/etc/commypanel/env</code2>)."
                                components={{ code: <code />, code2: <code /> }}
                            />
                        </p>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
