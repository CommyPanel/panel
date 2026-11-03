import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ServerAddressNote } from '@/components/domains/server-address-card';
import type { FormEvent } from 'react';
import AppDomainController from '@/actions/App/Http/Controllers/AppDomainController';
import { DomainCard } from '@/components/domains/domain-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { DomainRow, ServerAddress } from '@/types';

type Option = {
    value: string;
    label: string;
    hint?: string;
    disabled?: boolean;
};

function redirectOptions(name: string): Option[] {
    const clean = name.trim().toLowerCase().replace(/\.$/, '');
    const hasWww = clean.startsWith('www.');
    const bare = hasWww ? clean.slice(4) : clean;
    const options: Option[] = [{ value: 'none', label: 'No redirection' }];

    if (bare.includes('.')) {
        options.push(
            hasWww
                ? {
                      value: 'apex',
                      label: `Redirect www.${bare} to ${bare}`,
                      hint: `Both names are connected; ${bare} serves the app.`,
                  }
                : {
                      value: 'www',
                      label: `Redirect ${bare} to www.${bare}`,
                      hint: `Both names are connected; www.${bare} serves the app.`,
                  },
        );
    }

    options.push({
        value: 'wildcard',
        label: 'Wildcard domain (*.example.com)',
        hint: 'Coming later: it needs DNS provider integration.',
        disabled: true,
    });

    return options;
}

/** Add a domain to one app, with its optional www/bare redirect. */
export function AddAppDomainCard({ slug }: { slug: string }) {
    const form = useForm({ name: '', redirect: 'none' });
    const options = redirectOptions(form.data.name);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppDomainController.store(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <Card
            title="Add a domain"
            description="Use a domain or subdomain you control, for example app.example.com. Each domain belongs to one app."
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Domain"
                    placeholder="app.example.com"
                    required
                    value={form.data.name}
                    onChange={(e) => {
                        form.setData('name', e.target.value);
                        form.setData('redirect', 'none');
                    }}
                    error={form.errors.name}
                />
                <fieldset className="space-y-2">
                    <legend className="mb-1 label-text">Redirection</legend>
                    {options.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-start gap-3"
                        >
                            <input
                                type="radio"
                                name="redirect"
                                className="radio mt-0.5 radio-primary"
                                checked={form.data.redirect === option.value}
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
                <Button type="submit" loading={form.processing}>
                    <Plus className="size-4" aria-hidden />
                    Add domain
                </Button>
            </form>
        </Card>
    );
}

/** An app's domains with the DNS records still to create. */
export function AppDomains({
    slug,
    domains,
    server,
}: {
    slug: string;
    domains: DomainRow[];
    server: ServerAddress;
}) {
    return (
        <div className="space-y-6">
            <ServerAddressNote server={server} />
            <AddAppDomainCard slug={slug} />
            {domains.map((domain) => (
                <DomainCard key={domain.id} domain={domain} server={server} />
            ))}
        </div>
    );
}
