export type DomainStatus = 'pending' | 'verified' | 'misconfigured' | 'proxied';

export type DomainTls = 'secured' | 'pending' | 'failed' | 'none';

export type DomainRow = {
    id: number;
    name: string;
    status: DomainStatus;
    status_label: string;
    app: string | null;
    app_name: string | null;
    redirect_to: string | null;
    tls: DomainTls;
    found: { a: string[]; aaaa: string[] };
    problems: string[];
    last_checked_at: string | null;
};

export type ServerAddress = {
    ipv4: string | null;
    ipv6: string | null;
    /** 'auto' when the panel found it, 'manual' when a person entered it. */
    source: 'auto' | 'manual' | null;
    /** The panel is looking for it right now. */
    detecting: boolean;
    /** Nothing could be found; it has to be entered. */
    failed: boolean;
};

export type HttpsSettings = {
    enabled: boolean;
    email?: string | null;
    staging: boolean;
};

export type PanelSummary = {
    domain: string | null;
    dns_status: string | null;
    certificate: 'none' | 'issuing' | 'active' | 'failed';
    expires_at: string | null;
};

export type DomainCertificate = {
    status: 'issuing' | 'active' | 'failed';
    covers_domain: boolean;
    domains: string[];
    staging: boolean;
    issued_at: string | null;
    expires_at: string | null;
    last_error: string | null;
};
