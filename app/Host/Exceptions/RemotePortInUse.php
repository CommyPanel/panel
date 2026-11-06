<?php

namespace App\Host\Exceptions;

/** The public port picked for a database is taken by something else on the server. */
class RemotePortInUse extends HostOperationFailed {}
