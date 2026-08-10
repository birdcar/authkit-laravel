<?php

declare(strict_types=1);

namespace Authkit\Authkit\Enums;

use WorkOS\Resource\GenerateLinkIntent;

/**
 * Package-owned re-export of the SDK's Admin Portal intent enum, covering all
 * seven intents, so consumer code never imports WorkOS\Resource\* directly.
 */
enum PortalIntent: string
{
    case Sso = 'sso';
    case Dsync = 'dsync';
    case AuditLogs = 'audit_logs';
    case LogStreams = 'log_streams';
    case DomainVerification = 'domain_verification';
    case CertificateRenewal = 'certificate_renewal';
    case BringYourOwnKey = 'bring_your_own_key';

    public function toWorkos(): GenerateLinkIntent
    {
        return GenerateLinkIntent::from($this->value);
    }
}
