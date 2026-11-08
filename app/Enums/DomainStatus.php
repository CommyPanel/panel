<?php

namespace App\Enums;

enum DomainStatus: string
{
    /** Added, not checked yet. */
    case Pending = 'pending';
    /** DNS points at this server. */
    case Verified = 'verified';
    /** DNS exists but does not point at this server. */
    case Misconfigured = 'misconfigured';
    /** DNS points at a CDN proxy (Cloudflare), so it cannot be verified from outside. */
    case Proxied = 'proxied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('pending'),
            self::Verified => __('connected'),
            self::Misconfigured => __('needs attention'),
            self::Proxied => __('proxied'),
        };
    }
}
