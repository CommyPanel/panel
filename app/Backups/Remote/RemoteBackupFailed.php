<?php

namespace App\Backups\Remote;

use RuntimeException;

/**
 * A copy to or from the off-server storage failed. The message is fit to show.
 */
class RemoteBackupFailed extends RuntimeException {}
