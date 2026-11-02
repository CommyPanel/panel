<?php

namespace App\Backups;

use RuntimeException;

/**
 * A backup or restore could not be done. The message is fit to show.
 */
class BackupFailed extends RuntimeException {}
