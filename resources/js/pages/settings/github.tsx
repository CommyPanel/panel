import { router, useForm, usePage } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import GitHubController from '@/actions/App/Http/Controllers/GitHubController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import type { GitHubState } from '@/types';

type Props = {
    github: GitHubState;
    panel_ready: boolean;
    panel_name: string | null;
    repositories: { name: string; private: boolean }[];
    error: string | null;
    start: { action: string; state: string; manifest: string } | null;
};

export default function GitHub({
    github,
    panel_ready,
    repositories,
    error,
    start,
}: Props) {
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const form = useForm({ account: 'personal', organization: '' });
    const github_form = useRef<HTMLFormElement>(null);

    const prepare = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() =>
            form.post(GitHubController.prepare().url, { preserveScroll: true }),
        );
    };

    const disconnect = () =>
        void confirmed.run(() => {
            if (
                !window.confirm(
                    'Disconnect GitHub? Apps set up through it cannot fetch their code until you connect again.',
                )
            ) {
                return;
            }

            router.delete(GitHubController.destroy(), { preserveScroll: true });
        });

    return (
        <AppLayout title="GitHub">
            <PageHeader
                title="GitHub"
                description="Let CommyPanel read your repositories with its own GitHub app, and deploy on every push."
            />
            <div className="grid gap-6 xl:grid-cols-3">
                <div className="min-w-0 space-y-6 xl:col-span-2">
                    {errors.github && (
                        <Alert variant="error">{errors.github}</Alert>
                    )}
                    {error && <Alert variant="error">{error}</Alert>}

                    {!github.connected && (
                        <Card
                            title="1. Create the GitHub app"
                            description="CommyPanel makes its own GitHub app, so nobody else holds its key. You confirm on github.com, and GitHub sends the key straight back to this server."
                        >
                            {!panel_ready ? (
                                <Alert variant="warning">
                                    GitHub has to reach this panel over https.
                                    Set the panel&apos;s domain and wait for its
                                    certificate first (Domains &gt; Automatic
                                    HTTPS).
                                </Alert>
                            ) : start ? (
                                <form
                                    ref={github_form}
                                    method="post"
                                    action={`${start.action}?state=${encodeURIComponent(start.state)}`}
                                    className="space-y-4"
                                >
                                    <input
                                        type="hidden"
                                        name="manifest"
                                        value={start.manifest}
                                    />
                                    <p className="text-sm text-base-content/70">
                                        You will leave this panel for GitHub, to
                                        confirm the name and create the app.
                                        This is valid for ten minutes.
                                    </p>
                                    <Button type="submit">
                                        Continue to GitHub
                                    </Button>
                                </form>
                            ) : (
                                <form onSubmit={prepare} className="space-y-4">
                                    <Select
                                        label="Create it on"
                                        value={form.data.account}
                                        onChange={(e) =>
                                            form.setData(
                                                'account',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="personal">
                                            My GitHub account
                                        </option>
                                        <option value="organization">
                                            An organization
                                        </option>
                                    </Select>
                                    {form.data.account === 'organization' && (
                                        <Input
                                            label="Organization name"
                                            required
                                            autoComplete="off"
                                            spellCheck={false}
                                            value={form.data.organization}
                                            onChange={(e) =>
                                                form.setData(
                                                    'organization',
                                                    e.target.value,
                                                )
                                            }
                                            error={form.errors.organization}
                                            hint="The name in github.com/<name>. You must be an owner."
                                        />
                                    )}
                                    <Button
                                        type="submit"
                                        loading={form.processing}
                                    >
                                        Connect GitHub
                                    </Button>
                                </form>
                            )}
                        </Card>
                    )}

                    {github.connected && !github.installed && (
                        <Card
                            title="2. Install it"
                            description={`The app "${github.name}" exists. Now choose the account and the repositories it may read.`}
                        >
                            <div className="flex flex-wrap gap-2">
                                {github.install_url && (
                                    <a
                                        className="btn btn-primary"
                                        href={github.install_url}
                                    >
                                        Install on GitHub
                                    </a>
                                )}
                                <Button
                                    variant="soft"
                                    onClick={() =>
                                        router.post(
                                            GitHubController.refresh(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                    I installed it
                                </Button>
                            </div>
                        </Card>
                    )}

                    {github.installed && (
                        <Card
                            title="Connected"
                            description={`Installed on ${github.account}. New apps can pick one of these repositories.`}
                        >
                            {repositories.length === 0 ? (
                                <p className="text-sm text-base-content/70">
                                    The app may not read any repository yet.
                                    Change that on GitHub.
                                </p>
                            ) : (
                                <ul className="divide-y divide-base-300">
                                    {repositories.map((repo) => (
                                        <li
                                            key={repo.name}
                                            className="flex items-center justify-between py-2 text-sm"
                                        >
                                            <span className="min-w-0 truncate font-mono">
                                                {repo.name}
                                            </span>
                                            <span className="text-base-content/60">
                                                {repo.private
                                                    ? 'private'
                                                    : 'public'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap justify-end gap-2">
                                {github.settings_url && (
                                    <a
                                        className="btn btn-soft"
                                        href={github.settings_url}
                                    >
                                        App settings on GitHub
                                    </a>
                                )}
                                <Button
                                    variant="soft"
                                    onClick={() =>
                                        router.post(
                                            GitHubController.refresh(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                    Refresh
                                </Button>
                            </div>
                        </Card>
                    )}

                    {github.connected && (
                        <div className="flex justify-end">
                            <Button variant="ghost" onClick={disconnect}>
                                <Trash2 className="size-4" aria-hidden />
                                Disconnect
                            </Button>
                        </div>
                    )}
                </div>
                <div className="min-w-0">
                    <Card title="What this allows">
                        <ul className="list-disc space-y-2 pl-5 text-sm text-base-content/70">
                            <li>
                                The app can only <strong>read code</strong> of
                                the repositories you choose on GitHub. It cannot
                                write.
                            </li>
                            <li>
                                Each deployment gets a token for one repository
                                that expires after an hour.
                            </li>
                            <li>
                                Anyone who may create apps can deploy those
                                repositories. Give the app only what you want
                                deployable.
                            </li>
                            <li>
                                A push to an app&apos;s branch deploys it
                                automatically.
                            </li>
                            <li>
                                The panel contacts github.com only when you use
                                this page, create an app or deploy.
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
            {confirmed.modal}
        </AppLayout>
    );
}
