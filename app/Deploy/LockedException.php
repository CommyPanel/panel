<?php

namespace App\Deploy;

use RuntimeException;

/**
 * Something that a lock forbids was asked for, such as deleting a locked app.
 * The message says what to unlock and is fit to show to the user.
 */
class LockedException extends RuntimeException {}
