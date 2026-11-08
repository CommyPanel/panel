import { router } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import AppNginxController from '@/actions/App/Http/Controllers/AppNginxController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CodeEditor } from '@/components/ui/code-editor';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';

type NginxData = {
    custom: string;
    active: boolean;
    inactive_reason: string | null;
    generated: string | null;
    problem: string | null;
};

type Props = {
    slug: string;
    /** Tells the page whether there are unsaved changes, so it can warn before a tab switch. */
    onDirtyChange?: (dirty: boolean) => void;
};

/** Line endings and trailing blank space do not count as a change. */
const normalise = (text: string) => text.replace(/\r\n?/g, '\n').trimEnd();

const EXAMPLE = `# Allow uploads up to 50 MB
client_max_body_size 50m;
proxy_read_timeout 120s;

# Security headers
add_header X-Frame-Options "DENY" always;

# A path with its own settings that still goes to the app
location /api/ {
    proxy_read_timeout 300s;
    proxy_pass $commypanel_upstream;
}

# Redirect an old path
location = /old-page {
    return 301 /new-page;
}`;

/**
 * The app's custom nginx directives. They are added to the generated site, so
 * they survive deployments, domain changes and certificate renewals. Save
 * checks them, lets nginx test the whole config and reloads it; Cancel
 * throws the changes away and saves nothing.
 */
export function NginxTab({ slug, onDirtyChange }: Props) {
    const { t } = useT();
    const [data, setData] = useState<NginxData | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [draft, setDraft] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const confirmed = useConfirmedAction();
    // Saving is itself an Inertia visit; it must not trigger the leave warning.
    const sending = useRef(false);
    const errorId = useId();

    const url = AppNginxController.show(slug).url;

    const load = useCallback(async () => {
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(
                    t('The settings could not be loaded (:status).', {
                        status: response.status,
                    }),
                );
            }

            const body = (await response.json()) as NginxData;
            setData(body);
            setDraft(body.custom);
            setLoadError(null);
        } catch (e) {
            setLoadError(
                e instanceof Error
                    ? e.message
                    : t('The settings could not be loaded.'),
            );
        }
    }, [url, t]);

    useEffect(() => {
        void load();
    }, [load]);

    const dirty = data !== null && normalise(draft) !== normalise(data.custom);

    useEffect(() => {
        onDirtyChange?.(dirty);

        return () => onDirtyChange?.(false);
    }, [dirty, onDirtyChange]);

    // Closing the tab, reloading or following a link would lose the edits.
    useEffect(() => {
        if (!dirty) return;

        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);
        const removeInertia = router.on('before', (event) => {
            if (
                !sending.current &&
                !window.confirm(
                    t('You have unsaved nginx changes. Discard them?'),
                )
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeInertia();
        };
    }, [dirty, t]);

    const save = () => {
        if (!dirty || saving) return;

        void confirmed.run(() => {
            // Set before the visit starts: Inertia's `before` event comes first.
            sending.current = true;

            router.put(
                AppNginxController.update(slug),
                { custom: draft },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => {
                        setSaving(true);
                        setError(null);
                    },
                    onSuccess: () => void load(),
                    onError: (errors) =>
                        setError(
                            errors.custom ??
                                t('The changes could not be saved.'),
                        ),
                    onFinish: () => {
                        sending.current = false;
                        setSaving(false);
                    },
                },
            );
        });
    };

    const cancel = () => {
        setDraft(data?.custom ?? '');
        setError(null);
    };

    if (loadError) {
        return <Alert variant="error">{loadError}</Alert>;
    }

    if (data === null) {
        return (
            <p className="text-sm text-base-content/70" role="status">
                {t('Loading...')}
            </p>
        );
    }

    return (
        <div className="grid items-start gap-6 xl:grid-cols-3">
            <div className="min-w-0 space-y-6 xl:col-span-2">
                <Card
                    title={t('Custom nginx directives')}
                    description={t(
                        "Added to this app's generated site, so they stay when you deploy or change domains. Save checks them, lets nginx test the whole configuration and reloads it. Cancel discards your changes.",
                    )}
                >
                    {!data.active && data.inactive_reason && (
                        <Alert variant="info">
                            {t(
                                ':reason What you save here is kept and applied as soon as it does.',
                                { reason: data.inactive_reason },
                            )}
                        </Alert>
                    )}
                    {error && (
                        <Alert variant="error">
                            <span
                                id={errorId}
                                className="font-mono text-sm break-words whitespace-pre-wrap"
                            >
                                {error}
                            </span>
                        </Alert>
                    )}

                    <CodeEditor
                        label={t('Custom nginx directives')}
                        value={draft}
                        onChange={setDraft}
                        onSave={save}
                        disabled={saving}
                        invalid={error !== null}
                        describedBy={error ? errorId : undefined}
                    />
                    <p className="text-xs text-base-content/70">
                        {t(
                            'Tab indents. Press Esc, then Tab, to leave the editor. Cmd/Ctrl+S saves.',
                        )}
                    </p>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            onClick={save}
                            loading={saving}
                            disabled={!dirty}
                        >
                            {t('Save and reload nginx')}
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={cancel}
                            disabled={!dirty || saving}
                        >
                            {t('Cancel')}
                        </Button>
                        {dirty && (
                            <span className="text-sm text-base-content/70">
                                {t('Unsaved changes')}
                            </span>
                        )}
                    </div>
                </Card>
                {confirmed.modal}
            </div>

            <div className="min-w-0 space-y-6">
                <Card
                    title={t('What is allowed')}
                    description={t(
                        'Only safe directives are accepted, so a mistake or a stolen account cannot read files from the server.',
                    )}
                >
                    <ul className="list-disc space-y-1 pl-5 text-sm">
                        <li>
                            {t(
                                'Limits and timeouts: client_max_body_size, proxy_read_timeout, keepalive_timeout and similar.',
                            )}
                        </li>
                        <li>
                            {t(
                                'Compression: gzip, gzip_types and related settings.',
                            )}
                        </li>
                        <li>
                            {t(
                                'Headers: add_header, expires, proxy_hide_header.',
                            )}
                        </li>
                        <li>
                            {t(
                                'Access: allow and deny (an address or a network).',
                            )}
                        </li>
                        <li>
                            {t(
                                'location blocks. Inside them you can also use return, rewrite, if, proxy_set_header and proxy_pass $commypanel_upstream (the address of this app).',
                            )}
                        </li>
                    </ul>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'return, rewrite and if only work inside a location. A proxy_set_header in a location replaces the generated ones (Host, X-Real-IP, X-Forwarded-For, X-Forwarded-Proto) for that location, so repeat the ones you need. Anything else, such as root, alias, include or log files, is refused with the line it is on.',
                        )}
                    </p>
                    <details className="text-sm">
                        <summary className="cursor-pointer font-medium">
                            {t('Example')}
                        </summary>
                        <pre className="mt-2 overflow-x-auto rounded-field bg-base-200 p-3 font-mono text-xs">
                            {EXAMPLE}
                        </pre>
                    </details>
                </Card>

                <Card
                    title={t('Generated configuration')}
                    description={t(
                        'Read only. This is the site CommyPanel writes, with your directives in place.',
                    )}
                >
                    {data.problem && (
                        <Alert variant="warning">{data.problem}</Alert>
                    )}
                    {data.generated ? (
                        <details>
                            <summary className="cursor-pointer text-sm font-medium">
                                {t('Show the configuration')}
                            </summary>
                            <pre className="mt-2 max-h-96 overflow-auto rounded-field bg-base-200 p-3 font-mono text-xs">
                                {data.generated}
                            </pre>
                        </details>
                    ) : (
                        !data.problem && (
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'There is nothing to show until the app has a site.',
                                )}
                            </p>
                        )
                    )}
                </Card>
            </div>
        </div>
    );
}
