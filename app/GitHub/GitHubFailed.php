<?php

namespace App\GitHub;

use RuntimeException;

/**
 * Something with GitHub did not work. The message is safe to show: it never holds a token or key.
 */
class GitHubFailed extends RuntimeException {}
