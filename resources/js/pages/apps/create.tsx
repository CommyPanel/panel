import { Link, useForm } from '@inertiajs/react';
import { GitBranch, GitPullRequest, Package, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { AppLogo } from '@/components/apps/app-logo';
import { SetupSteps } from '@/components/apps/setup-steps';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

type GitHubRepos = {
    repositories: { name: string; branch: string; private: boolean }[];
    error: string | null;
};

type FastApp = {
    id: string;
    name: string;
    description: string;
    warnings: string[];
    /** The recipe's own memory limit in MB, or null for the server default. */
    memory_mb: number | null;
    /** The least memory the app can run in, or null. */
    min_memory_mb: number | null;
    guide: { automatic: string[]; after: string[]; upgrade: string[] };
    fields: {
        key: string;
        label: string;
        hint: string | null;
        options: { value: string; label: string }[];
    }[];
};

function GuideList({ title, items }: { title: string; items: string[] }) {
    return (
        <div>
            <h3 className="text-sm font-semibold">{title}</h3>
            <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-base-content/80">
                {items.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

/** What the chosen app is, what CommyPanel does for it, what is left to do and how it is upgraded. */
function AppGuide({ app }: { app: FastApp }) {
    const { t } = useT();

    return (
        <div className="space-y-4 rounded-box border border-base-300 bg-base-200/50 p-4">
            <div className="flex items-center gap-3">
                <AppLogo id={app.id} className="bg-base-100" />
                <div className="min-w-0">
                    <h3 className="font-semibold">{app.name}</h3>
                    <p className="text-sm text-base-content/80">
                        {app.description}
                    </p>
                </div>
            </div>
            <GuideList
                title={t('What CommyPanel does for you')}
                items={app.guide.automatic}
            />
            <GuideList
                title={t('What to do after it is running')}
                items={app.guide.after}
            />
            <GuideList title={t('Upgrading')} items={app.guide.upgrade} />
        </div>
    );
}

export default function Create({
    github,
    fast_apps,
}: {
    github: GitHubRepos | null;
    fast_apps: FastApp[];
}) {
    const { t } = useT();
    const form = useForm({
        template: '',
        settings: { elasticsearch: '' } as Record<string, string>,
        memory_limit_mb: '',
        name: '',
        stack_hint: '',
        source: 'git',
        git_url: '',
        git_branch: 'main',
        github_repo: '',
        github_auto_deploy: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppController.store());
    };

    const sources = [
        ...(github
            ? [
                  {
                      value: 'github',
                      title: t('GitHub repository'),
                      text: t(
                          'Pick one of the repositories you gave CommyPanel access to.',
                      ),
                      icon: GitPullRequest,
                  },
              ]
            : []),
        {
            value: 'git',
            title: t('Git repository'),
            text: t('GitHub, GitLab, Bitbucket or any Git server'),
            icon: GitBranch,
        },
        {
            value: 'upload',
            title: t("I'll upload it myself"),
            text: t('A .zip or .tar.gz of your project'),
            icon: Upload,
        },
        {
            value: 'template',
            title: t('A ready-made app'),
            text: t('Search, dashboards and more, no code needed'),
            icon: Package,
        },
    ];

    const quick = form.data.source === 'template';
    const chosen = fast_apps.find((app) => app.id === form.data.template);

    // Choosing an app suggests its name, unless the person already typed their own.
    const choose = (app: FastApp) =>
        form.setData((data) => ({
            ...data,
            template: app.id,
            // Each app starts from the memory its recipe is made for, which the person can change.
            memory_limit_mb:
                app.memory_mb === null ? '' : String(app.memory_mb),
            name:
                data.name === '' ||
                fast_apps.some((other) => other.name === data.name)
                    ? app.name
                    : data.name,
        }));

    return (
        <AppLayout title={t('New app')}>
            <PageHeader
                title={t('New app')}
                description={t(
                    'Tell us what to run and where the code lives. You will get a live view while it is built.',
                )}
            />
            <SetupSteps
                current="basics"
                source={
                    quick
                        ? 'template'
                        : form.data.source === 'upload'
                          ? 'upload'
                          : 'git'
                }
            />
            <form onSubmit={submit} className="space-y-6">
                <Card title={t('1. The basics')}>
                    <Input
                        label={t('App name')}
                        required
                        autoFocus
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    {!quick && (
                        <Select
                            label={t('App type (optional)')}
                            value={form.data.stack_hint}
                            onChange={(e) =>
                                form.setData('stack_hint', e.target.value)
                            }
                            error={form.errors.stack_hint}
                            hint={t(
                                'Leave on automatic and CommyPanel detects it from your code. A Dockerfile in your project always wins.',
                            )}
                        >
                            <option value="">
                                {t('Detect automatically')}
                            </option>
                            <option value="dotnet">.NET</option>
                            <option value="go">Go</option>
                            <option value="node">Node.js</option>
                        </Select>
                    )}
                    <fieldset className="space-y-2">
                        <legend className="mb-1 label-text">
                            {t('What do you want to run?')}
                        </legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {sources.map((source) => (
                                <label
                                    key={source.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-box border p-4',
                                        form.data.source === source.value
                                            ? 'border-primary bg-primary/5'
                                            : 'border-base-300',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="source"
                                        className="radio mt-1 radio-primary"
                                        checked={
                                            form.data.source === source.value
                                        }
                                        onChange={() =>
                                            form.setData('source', source.value)
                                        }
                                    />
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-2 font-medium">
                                            <source.icon
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {source.title}
                                        </span>
                                        <span className="block text-sm text-base-content/70">
                                            {source.text}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    {form.data.source === 'github' && github && (
                        <>
                            {github.error && (
                                <Alert variant="error">{github.error}</Alert>
                            )}
                            <Select
                                label={t('Repository')}
                                required
                                value={form.data.github_repo}
                                onChange={(e) => {
                                    const repo = github.repositories.find(
                                        (r) => r.name === e.target.value,
                                    );
                                    form.setData((data) => ({
                                        ...data,
                                        github_repo: e.target.value,
                                        git_branch:
                                            repo?.branch ?? data.git_branch,
                                    }));
                                }}
                                error={form.errors.github_repo}
                                hint={t(
                                    'Missing one? Give the CommyPanel app access to it on GitHub, then reload this page.',
                                )}
                            >
                                <option value="">
                                    {t('Choose a repository')}
                                </option>
                                {github.repositories.map((repo) => (
                                    <option key={repo.name} value={repo.name}>
                                        {repo.name}
                                        {repo.private
                                            ? ` ${t('(private)')}`
                                            : ''}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                label={t('Branch')}
                                required
                                value={form.data.git_branch}
                                onChange={(e) =>
                                    form.setData('git_branch', e.target.value)
                                }
                                error={form.errors.git_branch}
                            />
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    role="switch"
                                    className="switch mt-0.5 switch-primary"
                                    checked={form.data.github_auto_deploy}
                                    onChange={(e) =>
                                        form.setData(
                                            'github_auto_deploy',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span className="text-sm">
                                    {t('Deploy on every push to this branch')}
                                    <span className="block text-base-content/70">
                                        {form.data.git_branch
                                            ? t(
                                                  "Off by default. When on, each push to :branch builds and deploys the app by itself. You can change it later in the app's settings.",
                                                  {
                                                      branch: form.data
                                                          .git_branch,
                                                  },
                                              )
                                            : t(
                                                  "Off by default. When on, each push to the branch builds and deploys the app by itself. You can change it later in the app's settings.",
                                              )}
                                    </span>
                                </span>
                            </label>
                        </>
                    )}
                    {quick && (
                        <>
                            <fieldset className="space-y-2">
                                <legend className="mb-1 label-text">
                                    {t('Which app?')}
                                </legend>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                    {fast_apps.map((app) => (
                                        <label
                                            key={app.id}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-3 rounded-box border p-3 focus-within:ring-2 focus-within:ring-primary/40',
                                                form.data.template === app.id
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-base-300 hover:border-base-content/30',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="template"
                                                className="sr-only"
                                                checked={
                                                    form.data.template ===
                                                    app.id
                                                }
                                                onChange={() => choose(app)}
                                            />
                                            <AppLogo id={app.id} />
                                            <span className="min-w-0 font-medium">
                                                {app.name}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {form.errors.template && (
                                    <span className="block text-sm text-error">
                                        {form.errors.template}
                                    </span>
                                )}
                            </fieldset>
                            {chosen && <AppGuide app={chosen} />}
                            {chosen?.warnings.map((warning) => (
                                <Alert key={warning} variant="warning">
                                    {warning}
                                </Alert>
                            ))}
                            {chosen?.fields.map((field) => (
                                <Select
                                    key={field.key}
                                    label={field.label}
                                    required
                                    value={form.data.settings[field.key] ?? ''}
                                    onChange={(e) =>
                                        form.setData('settings', {
                                            ...form.data.settings,
                                            [field.key]: e.target.value,
                                        })
                                    }
                                    error={
                                        (form.errors as Record<string, string>)[
                                            `settings.${field.key}`
                                        ] ??
                                        (form.errors as Record<string, string>)
                                            .settings
                                    }
                                    hint={field.hint ?? undefined}
                                >
                                    <option value="">
                                        {field.options.length === 0
                                            ? t('None yet')
                                            : t('Choose one')}
                                    </option>
                                    {field.options.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </Select>
                            ))}
                            {chosen && (
                                <Input
                                    label={t('Memory limit (MB)')}
                                    type="number"
                                    min={chosen.min_memory_mb ?? 16}
                                    placeholder={t('Default')}
                                    value={form.data.memory_limit_mb}
                                    onChange={(e) =>
                                        form.setData(
                                            'memory_limit_mb',
                                            e.target.value,
                                        )
                                    }
                                    error={form.errors.memory_limit_mb}
                                    hint={
                                        chosen.memory_mb === null
                                            ? t(
                                                  "The most memory the app may use. Empty uses the server default (512 MB). You can change it later on the app's Settings tab.",
                                              )
                                            : chosen.min_memory_mb === null
                                              ? t(
                                                    'The most memory the app may use. The recommended amount is :mb MB; empty uses it.',
                                                    {
                                                        mb: String(
                                                            chosen.memory_mb,
                                                        ),
                                                    },
                                                )
                                              : t(
                                                    'The most memory the app may use. The recommended amount is :mb MB and it needs at least :min MB; empty uses the recommended amount.',
                                                    {
                                                        mb: String(
                                                            chosen.memory_mb,
                                                        ),
                                                        min: String(
                                                            chosen.min_memory_mb,
                                                        ),
                                                    },
                                                )
                                    }
                                />
                            )}
                        </>
                    )}
                    {form.data.source === 'git' && (
                        <>
                            <Input
                                label={t('Repository address')}
                                placeholder="https://github.com/you/app.git or git@github.com:you/app.git"
                                required
                                value={form.data.git_url}
                                onChange={(e) =>
                                    form.setData('git_url', e.target.value)
                                }
                                error={form.errors.git_url}
                            />
                            <Input
                                label={t('Branch')}
                                required
                                value={form.data.git_branch}
                                onChange={(e) =>
                                    form.setData('git_branch', e.target.value)
                                }
                                error={form.errors.git_branch}
                            />
                        </>
                    )}
                </Card>
                <div className="flex gap-2">
                    <Button type="submit" loading={form.processing}>
                        {t('Continue')}
                    </Button>
                    <Link href={AppController.index()} className="btn btn-text">
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
