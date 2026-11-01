import type { DomainRow } from './domain';

export type AppStatus =
    | 'setup'
    | 'new'
    | 'deploying'
    | 'running'
    | 'failed'
    | 'stopped'
    | 'deactivated';

export type AppStack = 'dotnet' | 'go' | 'node';

export type SetupStep = 'source' | 'settings' | 'domain';

export type SourceAccess = {
    state:
        | 'public'
        | 'key_required'
        | 'key_works'
        | 'branch_missing'
        | 'unreachable'
        | 'invalid';
    message: string;
    ready: boolean;
    branches: string[];
};

export type AppSetup = AppSettings & {
    setup_step: SetupStep;
    stack_hint: AppStack | null;
    source_verified: boolean;
    has_upload: boolean;
    ssh_url: string | null;
};

export type AppSummary = {
    slug: string;
    name: string;
    stack: string | null;
    source_type: 'git' | 'upload';
    status: AppStatus;
    git_url: string | null;
    git_branch: string;
    active_release: number | null;
    last_deployed_at: string | null;
};

export type AppSettings = AppSummary & {
    container_port: number;
    health_path: string;
    release_command: string | null;
    memory_limit_mb: number | null;
    cpu_limit: number | null;
    deploy_key_public: string | null;
    deactivated_release: number | null;
    url: string | null;
    domains: DomainRow[];
    certificate: CertificateSummary | null;
};

export type CertificateSummary = {
    status: 'issuing' | 'active' | 'failed';
    domains: string[];
    staging: boolean;
    issued_at: string | null;
    expires_at: string | null;
    last_error: string | null;
};

export type DeploymentStatus =
    | 'queued'
    | 'building'
    | 'starting'
    | 'active'
    | 'superseded'
    | 'failed'
    | 'stopped';

export type DeploymentStage =
    | 'fetch'
    | 'build'
    | 'release'
    | 'start'
    | 'health'
    | 'switch'
    | 'done';

export type DeploymentSummary = {
    number: number;
    status: DeploymentStatus;
    stage: DeploymentStage | null;
    finished: boolean;
    commit: string | null;
    commit_message: string | null;
    duration_seconds: number | null;
    is_rollback: boolean;
    has_release_command: boolean;
    can_roll_back: boolean;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type DeploymentDetail = DeploymentSummary & { log: string };
