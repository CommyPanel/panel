import { Input } from '@/components/ui/input';

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
    return (
        <div className="space-y-4">
            <Input
                label="Name"
                required
                value={data.name}
                onChange={(e) => setData('name', e.target.value)}
                error={errors.name}
            />
            <Input
                label="Git repository URL"
                placeholder="https://github.com/you/your-app.git"
                required
                value={data.git_url}
                onChange={(e) => setData('git_url', e.target.value)}
                error={errors.git_url}
                hint="https:// or ssh. For private repositories, create a deploy key after saving."
            />
            <Input
                label="Branch"
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
    return (
        <div className="space-y-4">
            <Input
                label="App port"
                type="number"
                min={1}
                max={65535}
                required
                value={data.container_port}
                onChange={(e) => setData('container_port', e.target.value)}
                error={errors.container_port}
                hint="The port your app listens on. It is also passed as PORT."
            />
            <Input
                label="Health check path"
                required
                value={data.health_path}
                onChange={(e) => setData('health_path', e.target.value)}
                error={errors.health_path}
                hint="A new version goes live once this path answers (anything below HTTP 500)."
            />
            <ReleaseCommandInput
                value={data.release_command}
                onChange={(value) => setData('release_command', value)}
                error={errors.release_command}
            />
            <Input
                label="Memory limit (MB)"
                type="number"
                min={16}
                placeholder="Default"
                value={data.memory_limit_mb}
                onChange={(e) => setData('memory_limit_mb', e.target.value)}
                error={errors.memory_limit_mb}
            />
            <Input
                label="CPU limit (cores)"
                type="number"
                min={0.05}
                step={0.05}
                placeholder="Default"
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
    return (
        <Input
            label="Release command"
            placeholder="Optional, e.g. npm run migrate"
            className="font-mono"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            error={error}
            spellCheck={false}
            autoComplete="off"
            hint="Runs once per deployment in a container from the new image, before it starts. If it fails, the old version keeps serving. Runs without a shell; use sh -c '...' to chain commands."
        />
    );
}
