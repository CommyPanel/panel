<?php

namespace App\Alerts;

use RuntimeException;

/**
 * A message could not be delivered. The text is safe to show: it never holds the address.
 */
class AlertFailed extends RuntimeException {}
