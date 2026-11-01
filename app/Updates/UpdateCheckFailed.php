<?php

namespace App\Updates;

use RuntimeException;

/**
 * Asking GitHub for releases did not work. The message is fit to show.
 */
class UpdateCheckFailed extends RuntimeException {}
