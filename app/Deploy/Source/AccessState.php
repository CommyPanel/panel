<?php

namespace App\Deploy\Source;

enum AccessState: string
{
    /** Readable without any credentials. */
    case Public = 'public';
    /** Not readable yet: private, or the address is wrong. A deploy key or access token is needed. */
    case KeyRequired = 'key_required';
    /** The deploy key works. */
    case KeyWorks = 'key_works';
    /** The access token works. */
    case TokenWorks = 'token_works';
    /** Readable, but the requested branch does not exist. */
    case BranchMissing = 'branch_missing';
    /** The server could not be reached. */
    case Unreachable = 'unreachable';
    /** The address or branch name is not acceptable. */
    case Invalid = 'invalid';

    public function isReady(): bool
    {
        return in_array($this, [self::Public, self::KeyWorks, self::TokenWorks], true);
    }
}
