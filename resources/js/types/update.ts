export type ReleaseSummary = {
    version: string;
    name: string;
    url: string;
    published_at: string | null;
    /** Rendered and made safe on the server; never contains raw HTML from GitHub. */
    notes_html: string;
};

export type UpdateInfo = {
    current: string;
    /** False for a development checkout, which has no version to compare. */
    supported: boolean;
    /** The automatic check after sign-in. */
    enabled: boolean;
    /** Switched off in the server configuration: not even a manual check is made. */
    forced_off: boolean;
    latest: string | null;
    available: boolean;
    newer: ReleaseSummary[];
    installed: ReleaseSummary | null;
    checked_at: string | null;
    error: string | null;
    releases_url: string;
};
