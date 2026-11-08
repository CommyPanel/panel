import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import AppSourceController from '@/actions/App/Http/Controllers/AppSourceController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';

/** Replace the project of an app created from an upload. */
export function UploadSourceCard({ slug }: { slug: string }) {
    const { t } = useT();
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ archive: File | null }>({ archive: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!form.data.archive) return;
        form.post(AppSourceController.upload(slug).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <Card
            title={t('Project files')}
            description={t(
                'This app runs the project you uploaded. Upload a .zip or .tar.gz to replace it, then press Deploy.',
            )}
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="w-full">
                    <label
                        htmlFor="archive"
                        className="mb-1 label-text block px-0!"
                    >
                        {t('New project archive')}
                    </label>
                    <input
                        ref={input}
                        id="archive"
                        type="file"
                        accept=".zip,.tar.gz,.tgz,application/zip,application/gzip"
                        className="input w-full"
                        onChange={(e) =>
                            form.setData('archive', e.target.files?.[0] ?? null)
                        }
                    />
                    {form.errors.archive && (
                        <span className="mt-1 block text-sm text-error">
                            {form.errors.archive}
                        </span>
                    )}
                </div>
                {form.progress && (
                    <progress
                        className="progress w-full progress-primary"
                        value={form.progress.percentage}
                        max={100}
                    />
                )}
                <Button
                    type="submit"
                    disabled={!form.data.archive}
                    loading={form.processing}
                >
                    <Upload className="size-4" aria-hidden />
                    {t('Upload')}
                </Button>
            </form>
        </Card>
    );
}
