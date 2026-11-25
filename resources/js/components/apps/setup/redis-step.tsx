import { Database, SkipForward, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import SetupRedisController from '@/actions/App/Http/Controllers/SetupRedisController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { jsonHeaders } from '@/lib/post-json';
import { cn } from '@/lib/utils';
import type { LaravelSetup } from '@/types';

type Mode = 'existing' | 'new' | 'none';
type Redis = LaravelSetup['redis'];

type Props = {
    slug: string;
    redis: Redis;
    /** Horizon runs on Redis, so a note shows when it is on and Redis is not chosen. */
    horizonOn: boolean;
    /** Tells the wizard whether a choice has been carried out. */
    onChange?: (state: { done: boolean; mode: Mode | null }) => void;
};

function ChoiceCard({
    mode,
    current,
    onSelect,
    icon,
    title,
    children,
    disabled,
}: {
    mode: Mode;
    current: Mode | '';
    onSelect: (mode: Mode) => void;
    icon: ReactNode;
    title: string;
    children: ReactNode;
    disabled?: boolean;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-3 rounded-box border border-base-300 p-4',
                current === mode && 'border-primary bg-primary/5',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <input
                type="radio"
                name="redis-mode"
                className="radio mt-1 radio-primary"
                checked={current === mode}
                disabled={disabled}
                onChange={() => onSelect(mode)}
            />
            <span className="min-w-0 space-y-1">
                <span className="flex items-center gap-2 font-medium">
                    {icon}
                    {title}
                </span>
                <span className="block text-sm text-base-content/70">
                    {children}
                </span>
            </span>
        </label>
    );
}

/**
 * The Redis choice of the Laravel wizard: a Redis made for this app, one you already run, or none. The
 * connection arrives as REDIS_URL, REDIS_HOST, REDIS_PORT and REDIS_PASSWORD.
 */
export function RedisStep({ slug, redis, horizonOn, onChange }: Props) {
    const { t } = useT();
    const saved = redis.choice.mode;
    const [mode, setMode] = useState<Mode | ''>(
        saved ?? (redis.suggested ? 'new' : 'none'),
    );
    const [app, setApp] = useState(
        redis.choice.app ?? redis.existing[0]?.slug ?? '',
    );
    const [current, setCurrent] = useState<Mode | null>(saved);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        onChange?.({ done: current !== null, mode: current });
    }, [current]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = async () => {
        if (mode === '') return;

        setSaving(true);
        setError(null);

        try {
            const response = await fetch(
                SetupRedisController.update(slug).url,
                {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    body: JSON.stringify(
                        mode === 'existing' ? { mode, app } : { mode },
                    ),
                },
            );

            if (response.status === 422) {
                const answer = (await response.json()) as {
                    errors?: Record<string, string[]>;
                };
                setError(
                    Object.values(answer.errors ?? {})[0]?.[0] ??
                        t('The request failed.'),
                );
            } else if (response.status === 403) {
                setError(t('You are not allowed to choose this option.'));
            } else if (!response.ok) {
                setError(
                    t('The request failed (:status).', {
                        status: response.status,
                    }),
                );
            } else {
                const answer = (await response.json()) as {
                    choice: { mode: Mode | null };
                };
                setCurrent(answer.choice.mode);
            }
        } catch {
            setError(t('The request failed.'));
        } finally {
            setSaving(false);
        }
    };

    const canLink = redis.can.link;
    const canCreate = redis.can.create;
    const unchanged =
        current === mode && (mode !== 'existing' || app === redis.choice.app);
    const ready =
        mode === 'none' ||
        mode === 'new' ||
        (mode === 'existing' && app !== '');

    return (
        <div className="space-y-4">
            {redis.suggested && (
                <Alert variant="info">
                    {t('Your code seems to use Redis (:why).', {
                        why: redis.why ?? '',
                    })}
                </Alert>
            )}
            {!canLink && (
                <Alert variant="warning">
                    {t(
                        'You do not have permission to link apps, so only "No Redis" is available.',
                    )}
                </Alert>
            )}
            <div className="space-y-3">
                <ChoiceCard
                    mode="new"
                    current={mode}
                    onSelect={setMode}
                    icon={<Zap className="size-4" aria-hidden />}
                    title={t('Make a Redis just for this app')}
                    disabled={!canLink || !canCreate}
                >
                    {t(
                        'A Redis server only for this app, started for you and linked. It has no web address and a generated password.',
                    )}
                </ChoiceCard>
                <ChoiceCard
                    mode="existing"
                    current={mode}
                    onSelect={setMode}
                    icon={<Database className="size-4" aria-hidden />}
                    title={t('Use a Redis you already run')}
                    disabled={!canLink || redis.existing.length === 0}
                >
                    {redis.existing.length === 0
                        ? t(
                              'You have no Redis app yet. Make one for this app instead.',
                          )
                        : t(
                              'Link one of your Redis apps. Apps that share a Redis share its data.',
                          )}
                </ChoiceCard>
                {mode === 'existing' && redis.existing.length > 0 && (
                    <Select
                        label={t('Redis app')}
                        value={app}
                        onChange={(e) => setApp(e.target.value)}
                    >
                        {redis.existing.map((option) => (
                            <option key={option.slug} value={option.slug}>
                                {option.name}
                            </option>
                        ))}
                    </Select>
                )}
                <ChoiceCard
                    mode="none"
                    current={mode}
                    onSelect={setMode}
                    icon={<SkipForward className="size-4" aria-hidden />}
                    title={t('No Redis')}
                >
                    {t(
                        'Cache, sessions and queues use files and the database. You can link a Redis later on the Links tab.',
                    )}
                </ChoiceCard>
            </div>
            {horizonOn && mode === 'none' && (
                <Alert variant="warning">{t('Horizon needs Redis.')}</Alert>
            )}
            {error && <Alert variant="error">{error}</Alert>}
            {current !== null && unchanged && (
                <Alert variant="success">{t('This choice is saved.')}</Alert>
            )}
            <Button
                onClick={save}
                loading={saving}
                disabled={!ready || unchanged}
            >
                {t('Use this choice')}
            </Button>
        </div>
    );
}
