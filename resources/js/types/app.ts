import type { DomainRow } from './domain';

export type AppStatus =
    | 'setup'
    | 'new'
    | 'deploying'
    | 'running'
    | 'failed'
    | 'stopped'
    | 'deactivated';

export type AppStack = 'dotnet' | 'go' | 'node' | 'python' | 'laravel';

export type SetupStep =
    | 'source'
    | 'settings'
    | 'database'
    | 'services'
    | 'volumes'
    | 'domain'
    | 'review';

/** What the wizard's Laravel steps know: what the code showed, the choices so far, and the last look. */
export type LaravelSetup = {
    /** Null while the code is still being looked at. */
    detected: {
        stack: string | null;
        error?: string;
        laravel_major?: number;
        php?: string;
        health_path?: string;
        node_manager?: string | null;
        has_build_script?: boolean;
        warnings?: string[];
    } | null;
    inspecting: boolean;
    stack_options: {
        php?: string;
        node_manager?: string;
        node?: number;
        ssr?: boolean;
    };
    /** The Redis choice of the wizard. */
    redis: {
        suggested: boolean;
        why: string | null;
        choice: {
            mode: 'existing' | 'new' | 'none' | null;
            app: string | null;
        };
        existing: { slug: string; name: string }[];
        can: { link: boolean; create: boolean };
    };
    review: {
        database: {
            mode: string | null;
            status: string | null;
            server: string | null;
            database: string | null;
            linked: { server: string; database: string; engine: string } | null;
        };
        processes: { name: string; kind: string; replicas: number }[];
        volumes: string[];
        domains: string[];
        env_count: number;
        linked_keys: string[];
    } | null;
};

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
    /** A Laravel app edits its variables as one .env text. */
    env_text: boolean;
    source_type: 'git' | 'upload' | 'template';
    /** The quick-install recipe the app was made from, if any. */
    template: {
        id: string;
        name: string;
        release: string;
        private: boolean;
    } | null;
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
    health_timeout: number | null;
    /** How a new release replaces the running one. */
    deploy_strategy: DeployStrategy;
    release_command: string | null;
    /** Laravel apps: the lines run before every release goes live. */
    release_script: string | null;
    /** Whether the Deployment script page exists for this app (its image has a shell). */
    has_release_script: boolean;
    /** Whether the app has extra processes (Laravel: scheduler, workers); the Services page is for these. */
    uses_processes: boolean;
    processes: AppProcess[];
    /** The live release's containers a person can look at: the web one first, then each process. */
    live_containers: LiveContainer[];
    /** Names of database servers made for this app; deleting the app keeps them. */
    dedicated_servers: string[];
    /** Variables, volumes or links changed after the running container was made. */
    pending_changes: boolean;
    alerts_enabled: boolean;
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
    status: 'issuing' | 'active' | 'failed' | 'awaiting_dns';
    domains: string[];
    staging: boolean;
    issued_at: string | null;
    expires_at: string | null;
    last_error: string | null;
    /** TXT records a wildcard certificate waits for, when no DNS provider is connected. */
    dns_pending: {
        records: { name: string; value: string }[];
        expires_at: string | null;
        renewal: boolean;
    } | null;
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

/** `rolling` starts the new version beside the old one; `stop_first` stops the old one first. */
export type DeployStrategy = 'rolling' | 'stop_first';

export type DeploymentSummary = {
    number: number;
    /** The mode this deployment used. */
    strategy: DeployStrategy;
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

export type LiveContainer = {
    container: string;
    label: string;
    process: string;
};

/** One extra process of an app, as the Services card shows it. */
export type AppProcess = {
    name: string;
    kind: 'scheduler' | 'queue' | 'horizon' | 'pulse' | 'ssr' | 'custom';
    kind_label: string;
    /** The person's command, or null for the usual one. */
    command: string | null;
    /** The usual command of its kind (for a custom one, what the inspector found). */
    default_command: string | null;
    enabled: boolean;
    replicas: number;
    may_replicate: boolean;
    overlap: 'overlap' | 'stop_first';
    memory_limit_mb: number | null;
    stop_grace_seconds: number;
    detected_by: string | null;
    /** False for a service CommyPanel cannot run yet (listed off, with its note). */
    supported: boolean;
    note: string | null;
    removable: boolean;
};
