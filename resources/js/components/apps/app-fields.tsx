import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';

export type AppFormData = {
    name: string;
    git_url: string;
    git_branch: string;
    container_port: string;
    health_path: string;
    release_command: string;
    memory_limit_mb: string;
    cpu_limit: string;
};

type Props = {
    data: AppFormData;
    errors: Partial<Record<keyof AppFormData, string>>;
    setData: <K extends keyof AppFormData>(
        key: K,
        value: AppFormData[K],
    ) => void;
};

/** Name and where the code comes from. Every field fills its container. */
export function SourceFields({ data, errors, setData }: Props) {
    const { t } = useT();

    return (
        <div className="space-y-4">
            <Input
                label={t('Name')}
                required
                value={data.name}
                onChange={(e) => setData('name', e.target.value)}
                error={errors.name}
            />
            <Input
                label={t('Git repository URL')}
                placeholder="https://github.com/you/your-app.git"
                required
                value={data.git_url}
                onChange={(e) => setData('git_url', e.target.value)}
                error={errors.git_url}
                hint={t(
                    'https:// or ssh. For private repositories, create a deploy key after saving.',
                )}
            />
            <Input
                label={t('Branch')}
                required
                value={data.git_branch}
                onChange={(e) => setData('git_branch', e.target.value)}
                error={errors.git_branch}
            />
        </div>
    );
}

/** How the app runs once built. */
export function RuntimeFields({ data, errors, setData }: Props) {
    const { t } = useT();

    return (
        <div className="space-y-4">
            <Input
                label={t('App port')}
                type="number"
                min={1}
                max={65535}
                required
                value={data.container_port}
                onChange={(e) => setData('container_port', e.target.value)}
                error={errors.container_port}
                hint={t(
                    'The port your app listens on. It is also passed as PORT.',
                )}
            />
            <Input
                label={t('Health check path')}
                required
                value={data.health_path}
                onChange={(e) => setData('health_path', e.target.value)}
                error={errors.health_path}
                hint={t(
                    'A new version goes live once this path answers (anything below HTTP 500).',
                )}
            />
            <ReleaseCommandInput
                value={data.release_command}
                onChange={(value) => setData('release_command', value)}
                error={errors.release_command}
            />
            <Input
                label={t('Memory limit (MB)')}
                type="number"
                min={16}
                placeholder={t('Default')}
                value={data.memory_limit_mb}
                onChange={(e) => setData('memory_limit_mb', e.target.value)}
                error={errors.memory_limit_mb}
            />
            <Input
                label={t('CPU limit (cores)')}
                type="number"
                min={0.05}
                step={0.05}
                placeholder={t('Default')}
                value={data.cpu_limit}
                onChange={(e) => setData('cpu_limit', e.target.value)}
                error={errors.cpu_limit}
            />
        </div>
    );
}

/**
 * Optional command run once per deployment, from the new image, before the
 * new version starts. Shared by the settings tab and the setup wizard.
 */
export function ReleaseCommandInput({
    value,
    onChange,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    const { t } = useT();

    return (
        <Input
            label={t('Release command')}
            placeholder={t('Optional, e.g. npm run migrate')}
            className="font-mono"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            error={error}
            spellCheck={false}
            autoComplete="off"
            hint={t(
                "Runs once per deployment in a container from the new image, before it starts. If it fails, the old version keeps serving. Runs without a shell; use sh -c '...' to chain commands.",
            )}
        />
    );
}
