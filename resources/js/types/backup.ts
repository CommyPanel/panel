export type PanelBackup = {
    name: string;
    size: number;
    created_at: string;
};

export type RemoteBackupSettings = {
    enabled: boolean;
    endpoint: string;
    region: string;
    bucket: string;
    prefix: string;
    access_key: string;
    keep: number;
    last_ok_at: string | null;
    last_error: string | null;
    has_secret: boolean;
};

export type GitHubState = {
    connected: boolean;
    installed: boolean;
    name: string | null;
    slug: string | null;
    account: string | null;
    install_url: string | null;
    settings_url: string | null;
};
