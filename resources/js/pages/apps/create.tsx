import { Link, useForm } from '@inertiajs/react';
import { GitBranch, GitPullRequest, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
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

export default function Create({ github }: { github: GitHubRepos | null }) {
    const { t } = useT();
    const form = useForm({
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
    ];

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
                source={form.data.source === 'upload' ? 'upload' : 'git'}
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
                        <option value="">{t('Detect automatically')}</option>
                        <option value="dotnet">.NET</option>
                        <option value="go">Go</option>
                        <option value="node">Node.js</option>
                    </Select>
                    <fieldset className="space-y-2">
                        <legend className="mb-1 label-text">
                            {t('Where is the code?')}
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
