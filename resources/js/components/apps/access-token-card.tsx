import { router, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import GitTokenController from '@/actions/App/Http/Controllers/GitTokenController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { AppSettings } from '@/types';

type Props = {
    app: Pick<AppSettings, 'slug' | 'git_token' | 'token_help'>;
};

/**
 * An HTTPS access token for a private repository, the alternative to a
 * deploy key. It is tested before it is saved and never shown again.
 */
export function AccessTokenCard({ app }: Props) {
    const help = app.token_help;
    const [replacing, setReplacing] = useState(false);
    const [removing, setRemoving] = useState(false);
    const form = useForm({ git_token: '', git_token_username: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(GitTokenController.store(app.slug).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setReplacing(false);
            },
        });
    };

    const remove = () =>
        router.delete(GitTokenController.destroy(app.slug), {
            preserveScroll: true,
            onStart: () => setRemoving(true),
            onFinish: () => setRemoving(false),
        });

    const saved = app.git_token !== null && !replacing;

    return (
        <Card
            title="Access token"
            description="Instead of a deploy key, give a read-only token. It is tested before it is saved, stored encrypted and never shown again."
        >
            {saved ? (
                <div className="space-y-3">
                    <p className="text-sm">
                        A token is saved
                        {app.git_token?.username && (
                            <>
                                {' '}
                                for{' '}
                                <span className="font-mono">
                                    {app.git_token.username}
                                </span>
                            </>
                        )}
                        . Deployments use it instead of the deploy key.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="soft"
                            size="sm"
                            onClick={() => setReplacing(true)}
                        >
                            Replace token
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={remove}
                            loading={removing}
                        >
                            Remove token
                        </Button>
                    </div>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    {help && help.steps.length > 0 && (
                        <div className="space-y-2 text-sm">
                            <ol className="list-decimal space-y-1 pl-5">
                                {help.steps.map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                            {help.url && (
                                <a
                                    className="link link-primary"
                                    href={help.url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    Create a token on {help.provider}
                                </a>
                            )}
                        </div>
                    )}
                    {help?.username === null && (
                        <Input
                            label="Username"
                            required
                            autoComplete="off"
                            value={form.data.git_token_username}
                            onChange={(e) =>
                                form.setData(
                                    'git_token_username',
                                    e.target.value,
                                )
                            }
                            error={form.errors.git_token_username}
                            hint="The account the token belongs to."
                        />
                    )}
                    <Input
                        label="Token"
                        type="password"
                        required
                        autoComplete="new-password"
                        spellCheck={false}
                        className="font-mono"
                        value={form.data.git_token}
                        onChange={(e) =>
                            form.setData('git_token', e.target.value)
                        }
                        error={form.errors.git_token}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="soft"
                            loading={form.processing}
                            disabled={form.data.git_token === ''}
                        >
                            <KeyRound className="size-4" aria-hidden />
                            Test and save token
                        </Button>
                        {replacing && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    form.reset();
                                    form.clearErrors();
                                    setReplacing(false);
                                }}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </form>
            )}
        </Card>
    );
}
