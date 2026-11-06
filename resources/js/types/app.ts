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
        | 'token_works'
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
    locked: boolean;
    git_url: string | null;
    git_branch: string;
    active_release: number | null;
    last_deployed_at: string | null;
};

export type AppVolume = {
    id: number;
    name: string;
    path: string;
    locked: boolean;
};

export type AppSettings = AppSummary & {
    container_port: number;
    health_path: string;
    release_command: string | null;
    /** Variables, volumes or links changed after the running container was made. */
    pending_changes: boolean;
    safe_deploys: SafeDeploys;
    volumes: AppVolume[];
    memory_limit_mb: number | null;
    cpu_limit: number | null;
    deploy_key_public: string | null;
    git_token: { username: string | null } | null;
    token_help: TokenHelp | null;
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
    | 'stopped'
    /** A share of the visitors is on this release while it is being proven. */
    | 'rollout'
    /** It went live, did clearly worse, and traffic was moved back. */
    | 'rolled_back';

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
    /** Recreates the container from the live image to apply variables, volumes and links. */
    is_apply: boolean;
    /** Going live again, on purpose, after an automatic rollback. */
    is_retry: boolean;
    can_retry: boolean;
    watch: { state: string; until: string | null; note: string | null } | null;
    has_release_command: boolean;
    can_roll_back: boolean;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type DeploymentDetail = DeploymentSummary & { log: string };

/** How to create a read-only access token on the app's Git host. */
export type TokenHelp = {
    provider: string;
    /** The username the host expects with a token; null when the user must enter it. */
    username: string | null;
    url: string | null;
    steps: string[];
};

/** The app's push webhook; null for uploaded apps. */
export type AppWebhook = {
    enabled: boolean;
    /** Read through the GitHub App: a single switch instead of an address and secret. */
    github?: boolean;
    branch?: string;
    provider: string;
    /** Where to add the webhook on the Git host. */
    settings_url: string | null;
    steps: string[];
    url?: string;
    /** Only for people who may change the app. */
    secret?: string | null;
    on_panel_domain?: boolean;
};

/** The settings of watching new releases and of gradual rollouts. */
export type SafeDeploys = {
    watch_enabled: boolean;
    watch_minutes: number;
    rollout_enabled: boolean;
    rollout_steps: number[];
    rollout_hold_minutes: number;
    rollout_auto: boolean;
};

export type SafeStat = {
    requests: number;
    errors: number;
    error_percent: number;
    p95_ms: number;
};

/** What the deployment page shows about a gradual rollout or a watch. */
export type SafeDeployInfo = {
    rollout: {
        state: 'running' | 'waiting' | 'promoted' | 'aborted' | 'rolled_back';
        percent: number;
        step: number;
        steps: number[];
        hold_minutes: number;
        auto: boolean;
        step_started_at: string;
        step_ends_at: string;
        note: string | null;
        previous_number: number | null;
    } | null;
    traffic: {
        new: SafeStat | null;
        reference: SafeStat | null;
        verdict: { state: 'ok' | 'bad' | 'unknown'; reason: string };
        /** The release being compared with, for a watch. */
        previous_number?: number | null;
    } | null;
};
