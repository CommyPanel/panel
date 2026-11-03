import { router, useForm, usePage } from '@inertiajs/react';
import { Send, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { AlertSettings } from '@/types';

export default function Notifications({ alerts }: { alerts: AlertSettings }) {
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const form = useForm({
        enabled: alerts.enabled,
        format: alerts.format,
        events: alerts.events,
        url: '',
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() =>
            form.put(NotificationController.update().url, {
                preserveScroll: true,
                onSuccess: () => form.setData('url', ''),
            }),
        );
    };

    const test = () =>
        form.post(NotificationController.test().url, { preserveScroll: true });

    const remove = () =>
        void confirmed.run(() => {
            if (!window.confirm('Remove the address? No messages are sent.')) {
                return;
            }

            router.delete(NotificationController.destroy(), {
                preserveScroll: true,
            });
        });

    return (
        <AppLayout title="Notifications">
            <PageHeader
                title="Notifications"
                description="Get told in a chat when a deployment finishes or fails."
            />
            <Card
                title="Deployment messages"
                description="Slack, Mattermost, Discord or any service that takes a webhook. The message holds the app, the commit and, on a failure, the reason. Never the log or any secret."
            >
                {errors.alerts && (
                    <Alert variant="error">{errors.alerts}</Alert>
                )}
                {alerts.has_url && alerts.last_error && (
                    <Alert variant="error">
                        The last message could not be sent: {alerts.last_error}
                    </Alert>
                )}
                {alerts.has_url && alerts.enabled && alerts.last_ok_at && (
                    <p className="text-sm text-base-content/70">
                        Last message sent {timeAgo(alerts.last_ok_at)} to{' '}
                        {alerts.host}.
                    </p>
                )}
                <form onSubmit={save} className="space-y-4">
                    <Input
                        label="Webhook address"
                        type="password"
                        autoComplete="new-password"
                        spellCheck={false}
                        required={!alerts.has_url}
                        placeholder={
                            alerts.has_url
                                ? `Saved for ${alerts.host}. Type to replace it.`
                                : 'https://hooks.slack.com/services/...'
                        }
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                        error={form.errors.url}
                        hint="Stored encrypted and never shown again, because the address is the secret of the channel. It must be https."
                    />
                    <Select
                        label="Format"
                        value={form.data.format}
                        onChange={(e) =>
                            form.setData(
                                'format',
                                e.target.value as AlertSettings['format'],
                            )
                        }
                        error={form.errors.format}
                        hint="Slack also covers Mattermost and Rocket.Chat. JSON sends the same text plus app, deployment and result as fields."
                    >
                        <option value="slack">Slack and compatible</option>
                        <option value="discord">Discord</option>
                        <option value="json">Generic JSON</option>
                    </Select>
                    <Select
                        label="Send a message"
                        value={form.data.events}
                        onChange={(e) =>
                            form.setData(
                                'events',
                                e.target.value as AlertSettings['events'],
                            )
                        }
                        error={form.errors.events}
                    >
                        <option value="failures">
                            Only when a deployment fails
                        </option>
                        <option value="all">
                            When a deployment succeeds or fails
                        </option>
                    </Select>
                    <label className="flex cursor-pointer items-center gap-3">
                        <input
                            type="checkbox"
                            className="checkbox"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                        />
                        <span className="text-sm">
                            Send deployment results to this address
                        </span>
                    </label>
                    <p className="text-sm text-base-content/70">
                        The message is sent from this server, in the background,
                        and only once you switch it on here. It is one of the
                        few places where the panel contacts the outside by
                        itself. Addresses that point at this server or a
                        reserved range are refused.
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        {alerts.has_url && (
                            <Button variant="ghost" onClick={remove}>
                                <Trash2 className="size-4" aria-hidden />
                                Remove
                            </Button>
                        )}
                        <Button
                            variant="soft"
                            onClick={test}
                            loading={form.processing}
                        >
                            <Send className="size-4" aria-hidden />
                            Send a test message
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>
                {confirmed.modal}
            </Card>
        </AppLayout>
    );
}
