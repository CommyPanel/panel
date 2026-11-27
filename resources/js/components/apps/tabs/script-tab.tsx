import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import AppScriptController from '@/actions/App/Http/Controllers/AppScriptController';
import { ReleaseScriptInput } from '@/components/apps/app-fields';
import { scriptExample } from '@/components/apps/script-examples';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import type { AppSettings } from '@/types';

/**
 * The deployment script: the steps a release runs before it goes live (migrations, cache warming, ...).
 * An app that still has the single release command starts with it as the first line; saving moves it here.
 */
export function ScriptTab({
    app,
    onDirtyChange,
}: {
    app: AppSettings;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const { t } = useT();
    const initial = app.release_script ?? app.release_command ?? '';
    const form = useForm({ release_script: initial });
    const dirty = form.data.release_script !== initial;

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = () => {
        if (!dirty || form.processing) return;

        form.submit(AppScriptController.update(app.slug), {
            preserveScroll: true,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <form onSubmit={submit} className="min-w-0 space-y-6">
            {app.release_script === null && app.release_command !== null && (
                <Alert variant="info">
                    {t(
                        'This app had a single release command. Saving moves it into the script.',
                    )}
                </Alert>
            )}
            <Card
                title={t('Deployment script')}
                description={t(
                    'The steps a release runs before it goes live, for example database migrations. Changes apply from the next deployment.',
                )}
            >
                <ReleaseScriptInput
                    value={form.data.release_script}
                    onChange={(value) => form.setData('release_script', value)}
                    error={form.errors.release_script}
                    placeholder={scriptExample(app.stack)}
                    onSave={save}
                    minLines={14}
                    maxLines={32}
                />
                <p className="mt-3 text-sm text-base-content/70">
                    {t(
                        'The script runs in the finished image, after the build. Build tools such as pnpm or vite are not in it; change the build itself in package.json or your Dockerfile.',
                    )}
                </p>
                <p className="mt-2 text-xs text-base-content/70">
                    {t(
                        'Tab indents. Press Esc, then Tab, to leave the editor. Cmd/Ctrl+S saves.',
                    )}
                </p>
            </Card>
            <Button type="submit" loading={form.processing} disabled={!dirty}>
                {t('Save script')}
            </Button>
        </form>
    );
}
