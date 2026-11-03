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

export type AlertSettings = {
    enabled: boolean;
    format: 'slack' | 'discord' | 'json';
    events: 'failures' | 'all';
    host: string;
    has_url: boolean;
    last_ok_at: string | null;
    last_error: string | null;
};
