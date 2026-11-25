import { useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useDomainProgress } from '@/hooks/use-domain-progress';
import { ServerAddressNote } from '@/components/domains/server-address-card';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AppDomainController from '@/actions/App/Http/Controllers/AppDomainController';
import { DomainCard } from '@/components/domains/domain-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import type { DomainRow, ServerAddress } from '@/types';

type Option = {
    value: string;
    label: string;
    hint?: string;
    disabled?: boolean;
};

function redirectOptions(
    name: string,
    t: (key: string, params?: Record<string, string>) => string,
): Option[] {
    const clean = name.trim().toLowerCase().replace(/\.$/, '');
    const hasWww = clean.startsWith('www.');
    const bare = hasWww ? clean.slice(4) : clean;
    const options: Option[] = [{ value: 'none', label: t('No redirection') }];

    // A wildcard has no www partner: the bare domain is added on its own.
    if (clean.startsWith('*.')) {
        return [
            {
                value: 'none',
                label: t('No redirection'),
                hint: t(
                    'A wildcard serves every name one level below the domain, but not the bare domain itself. Add the bare domain separately if it should be served too.',
                ),
            },
        ];
    }

    if (bare.includes('.')) {
        options.push(
            hasWww
                ? {
                      value: 'apex',
                      label: t('Redirect www.:name to :name', { name: bare }),
                      hint: t(
                          'Both names are connected; :name serves the app.',
                          {
                              name: bare,
                          },
                      ),
                  }
                : {
                      value: 'www',
                      label: t('Redirect :name to www.:name', { name: bare }),
                      hint: t(
                          'Both names are connected; www.:name serves the app.',
                          { name: bare },
                      ),
                  },
        );
    }

    return options;
}

/** Add a domain to one app, with its optional www/bare redirect. */
export type DomainDraft = { name: string; redirect: string };

export function AddAppDomainCard({
    slug,
    onDraft,
}: {
    slug: string;
    onDraft?: (draft: DomainDraft) => void;
}) {
    const { t } = useT();
    const free =
        (usePage().props as { free_domains?: string[] }).free_domains ?? [];
    const [mode, setMode] = useState<'type' | 'free'>('type');
    const form = useForm({ name: '', redirect: 'none' });
    const options = redirectOptions(form.data.name, t);
    const choosing = mode === 'free' && free.length > 0;
    const typed = form.data.name.trim() !== '';

    // Lets a parent (the setup wizard) know about a domain that was typed but not added.
    useEffect(() => {
        onDraft?.({
            name: form.data.name.trim(),
            redirect: form.data.redirect,
        });
    }, [form.data.name, form.data.redirect]); // eslint-disable-line react-hooks/exhaustive-deps

    const switchTo = (next: 'type' | 'free') => {
        setMode(next);
        form.setData({
            name: next === 'free' ? (free[0] ?? '') : '',
            redirect: 'none',
        });
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppDomainController.store(slug), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setMode('type');
            },
        });
    };

    return (
        <Card
            title={t('Add a domain')}
            description={t(
                'Use a domain or subdomain you control, for example app.example.com. Each domain belongs to one app.',
            )}
        >
            <form onSubmit={submit} className="space-y-4">
                {free.length > 0 && (
                    <Tabs
                        label={t('How to choose the domain')}
                        active={mode}
                        onChange={switchTo}
                        tabs={[
                            { id: 'type', label: t('Type a domain') },
                            {
                                id: 'free',
                                label: t('Use a free domain'),
                                badge: free.length,
                            },
                        ]}
                    />
                )}
                {choosing ? (
                    <Select
                        label={t('Free domain')}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        hint={t(
                            'Domains CommyPanel already knows that no app uses, for example after deleting an app. DNS is checked again.',
                        )}
                    >
                        {free.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </Select>
                ) : (
                    <Input
                        label={t('Domain')}
                        placeholder="app.example.com"
                        required
                        value={form.data.name}
                        onChange={(e) => {
                            form.setData('name', e.target.value);
                            form.setData('redirect', 'none');
                        }}
                        error={form.errors.name}
                        hint={t(
                            'A wildcard such as *.example.com serves every subdomain. Its certificate is checked through DNS: connect Cloudflare under Domains, or create a TXT record by hand.',
                        )}
                    />
                )}
                {!choosing && (
                    <fieldset className="space-y-2">
                        <legend className="mb-1 label-text">
                            {t('Redirection')}
                        </legend>
                        {options.map((option) => (
                            <label
                                key={option.value}
                                className="flex items-start gap-3"
                            >
                                <input
                                    type="radio"
                                    name="redirect"
                                    className="radio mt-0.5 radio-primary"
                                    checked={
                                        form.data.redirect === option.value
                                    }
                                    disabled={option.disabled}
                                    onChange={() =>
                                        form.setData('redirect', option.value)
                                    }
                                />
                                <span className="min-w-0 text-sm">
                                    <span
                                        className={
                                            option.disabled
                                                ? 'text-base-content/60'
                                                : undefined
                                        }
                                    >
                                        {option.label}
                                    </span>
                                    {option.hint && (
                                        <span className="block text-base-content/60">
                                            {option.hint}
                                        </span>
                                    )}
                                </span>
                            </label>
                        ))}
                        {form.errors.redirect && (
                            <span className="block text-sm text-error">
                                {form.errors.redirect}
                            </span>
                        )}
                    </fieldset>
                )}
                <Button type="submit" loading={form.processing}>
                    <Plus className="size-4" aria-hidden />
                    {t('Add domain')}
                </Button>
                {typed && !form.processing && (
                    <p
                        className="text-sm font-medium text-warning"
                        role="status"
                    >
                        {t(
                            ':domain is not added yet. Press "Add domain" to save it.',
                            { domain: form.data.name.trim() },
                        )}
                    </p>
                )}
            </form>
        </Card>
    );
}

/** An app's domains with the DNS records still to create. */
export function AppDomains({
    slug,
    domains,
    server,
    onDraft,
}: {
    slug: string;
    domains: DomainRow[];
    server: ServerAddress;
    onDraft?: (draft: DomainDraft) => void;
}) {
    const { t } = useT();
    const progress = useDomainProgress(domains, ['app']);

    return (
        <div className="space-y-6">
            <ServerAddressNote server={server} />
            {progress.waiting && (
                <p className="text-sm text-base-content/70" role="status">
                    {t(
                        'Waiting for DNS or the certificate. This page checks by itself every 5 seconds and updates when something changes; DNS changes can take a few minutes, sometimes longer.',
                    )}
                </p>
            )}
            {progress.gaveUp && (
                <p className="text-sm text-base-content/70" role="status">
                    {t(
                        'Stopped checking automatically after half an hour. CommyPanel still re-checks every five minutes; press Check DNS to look again now.',
                    )}
                </p>
            )}
            <AddAppDomainCard slug={slug} onDraft={onDraft} />
            {domains.map((domain) => (
                <DomainCard key={domain.id} domain={domain} server={server} />
            ))}
        </div>
    );
}
