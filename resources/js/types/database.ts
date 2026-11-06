export type DatabaseStatus = 'provisioning' | 'running' | 'stopped' | 'failed';

export type DatabaseServer = {
    slug: string;
    name: string;
    engine: 'mariadb' | 'mysql' | 'postgres';
    engine_label: string;
    /** The tool a database of this server opens in: phpMyAdmin or pgAdmin. */
    tool: string;
    version: string;
    status: DatabaseStatus;
    status_label: string;
    error: string | null;
    host: string;
    port: number;
    databases_count: number;
    created_at: string | null;
};

export type ManagedDatabase = {
    id: number;
    name: string;
    username: string;
    apps: { slug: string; name: string }[];
    /** Set while a backup is being loaded into this database, or when that failed. */
    restore: {
        status: 'restoring' | 'failed';
        from: string | null;
        error: string | null;
    } | null;
    created_at: string | null;
};

export type EngineChoice = {
    value: string;
    label: string;
    versions: string[];
};

export type DatabaseCredentials = {
    host: string;
    port: number;
    name: string;
    user: string;
    password: string;
    url: string;
};

export type LinksOverview = {
    databases: {
        id: number;
        prefix: string;
        database: string;
        server: string;
        server_slug: string;
        engine: string;
        running: boolean;
    }[];
    apps: { id: number; prefix: string; app: string; slug: string }[];
    used_by: { app: string; slug: string }[];
    available_databases: { id: number; label: string }[];
    available_apps: { slug: string; name: string }[];
};

export type DatabaseBackupFile = {
    name: string;
    database: string;
    size: number;
    created_at: string;
};

/** A database server reached from the internet, as the page shows it. */
export type RemoteAccess = {
    /** null when closed. */
    status: 'opening' | 'open' | 'failed' | null;
    error: string | null;
    port: number | null;
    /** The address clients connect to. */
    host: string;
    allow: string[];
    forever: boolean;
    expires_at: string | null;
    opened_at: string | null;
    opened_by: string | null;
    /** Whether the server runs with TLS (it does once it was opened). */
    tls: boolean;
};
