import { router } from '@inertiajs/react';
import { Download, Eye, EyeOff, RotateCcw, Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import EnvTextController from '@/actions/App/Http/Controllers/EnvTextController';
import EnvVarController from '@/actions/App/Http/Controllers/EnvVarController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CodeEditor } from '@/components/ui/code-editor';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { highlightEnv } from '@/lib/env-highlight';
import { jsonHeaders } from '@/lib/post-json';

type Props = {
    slug: string;
    /** How many variables the app has now. */
    count: number;
    /** Names the app's links provide (database, other apps); never values. */
    linkedKeys: string[];
    /** Tells the page whether there are unsaved changes, so leaving the tab can ask. */
    onDirtyChange?: (dirty: boolean) => void;
};

/** The names a .env text defines, for noticing which ones replace a link's. */
function definedKeys(text: string): Set<string> {
    const keys = new Set<string>();

    for (const line of text.split('\n')) {
        const match = line.match(
            /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/,
        );

        if (match) {
            keys.add(match[1]);
        }
    }

    return keys;
}

/**
 * A Laravel app's variables as one .env text. The values are secrets: the text is fetched only after
 * a password confirmation (and that is written to the audit log), kept in this component only, and
 * dropped again when it is hidden. Saving sends the whole text; the panel works out which variables
 * changed.
 */
export function EnvEditorCard({
    slug,
    count,
    linkedKeys,
    onDirtyChange,
}: Props) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const [saved, setSaved] = useState<string | null>(null);
    const [text, setText] = useState('');
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const dirty = saved !== null && text !== saved;

    useEffect(() => {
        onDirtyChange?.(dirty);

        return () => onDirtyChange?.(false);
    }, [dirty]); // eslint-disable-line react-hooks/exhaustive-deps

    const load = () =>
        void confirmed.run(async () => {
            setLoading(true);
            setLoadError(null);

            try {
                const response = await fetch(EnvTextController.show(slug).url, {
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                });

                if (!response.ok) {
                    throw new Error('failed');
                }

                const body = (await response.json()) as { text: string };
                setSaved(body.text);
                setText(body.text);
                setProblem(null);
            } catch {
                setLoadError(
                    t('The variables could not be loaded. Try again.'),
                );
            } finally {
                setLoading(false);
            }
        });

    const save = () => {
        if (!dirty || saving) {
            return;
        }

        void confirmed.run(() =>
            router.put(
                EnvTextController.update(slug),
                { text },
                {
                    preserveScroll: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                    onSuccess: () => {
                        setSaved(text);
                        setProblem(null);
                    },
                    onError: (errors) =>
                        setProblem(
                            errors.text ?? Object.values(errors)[0] ?? null,
                        ),
                },
            ),
        );
    };

    const discard = () => {
        setText(saved ?? '');
        setProblem(null);
    };

    const hide = () => {
        setSaved(null);
        setText('');
        setProblem(null);
    };

    const download = () =>
        void confirmed.run(() => {
            window.location.assign(EnvVarController.download(slug).url);
        });

    const shadowed =
        saved === null
            ? []
            : linkedKeys.filter((key) => definedKeys(text).has(key));

    return (
        <Card
            title={t('Environment (.env)')}
            description={t(
                'The variables of this app as one .env file: comments and order are kept. The values are secrets, so they are shown only after you confirm your password, and that is recorded in the audit log. Changes reach the running app when you apply them (a button appears on the app) or deploy. PORT is set for you.',
            )}
        >
            {confirmed.modal}
            {loadError && <Alert variant="error">{loadError}</Alert>}

            {saved === null ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="text-sm text-base-content/70">
                        {t(':count variable(s)', { count })}
                    </span>
                    <Button onClick={load} loading={loading}>
                        <Eye className="size-4" aria-hidden />
                        {t('Show variables')}
                    </Button>
                </div>
            ) : (
                <div className="space-y-4">
                    {problem && (
                        <Alert variant="error">
                            <span className="whitespace-pre-line">
                                {problem}
                            </span>
                        </Alert>
                    )}

                    <CodeEditor
                        value={text}
                        onChange={(value) => setText(value)}
                        label={t('The .env file of this app')}
                        onSave={save}
                        invalid={problem !== null}
                        highlight={highlightEnv}
                        minLines={14}
                        maxLines={32}
                    />

                    {shadowed.length > 0 && (
                        <p className="text-sm text-warning">
                            {t(
                                'These lines replace what a link of this app provides: :names',
                                { names: shadowed.join(', ') },
                            )}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            onClick={save}
                            loading={saving}
                            disabled={!dirty}
                        >
                            <Save className="size-4" aria-hidden />
                            {t('Save')}
                        </Button>
                        <Button
                            variant="soft"
                            onClick={discard}
                            disabled={!dirty || saving}
                        >
                            <RotateCcw className="size-4" aria-hidden />
                            {t('Discard changes')}
                        </Button>
                        <Button variant="soft" onClick={download}>
                            <Download className="size-4" aria-hidden />
                            {t('Download as .env file')}
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={hide}
                            disabled={dirty || saving}
                        >
                            <EyeOff className="size-4" aria-hidden />
                            {t('Hide')}
                        </Button>
                    </div>
                </div>
            )}

            {linkedKeys.length > 0 && (
                <p className="text-sm text-base-content/70">
                    {t(
                        'Provided by the links of this app, not part of this text:',
                    )}{' '}
                    <code className="break-words">{linkedKeys.join(', ')}</code>
                </p>
            )}
        </Card>
    );
}
