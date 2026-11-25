<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Issuing = 'issuing';
    case Active = 'active';
    case Failed = 'failed';
    /** A wildcard certificate waits for the person to create a TXT record at their DNS provider. */
    case AwaitingDns = 'awaiting_dns';
}
