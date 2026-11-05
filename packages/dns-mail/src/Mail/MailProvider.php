<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

/** Where the mailboxes of a domain live. */
enum MailProvider: string
{
    /** Mailboxes on this server (Postfix + Dovecot + Rspamd, webmail via SnappyMail). */
    case Rhis = 'rhis';

    /** Mailboxes in Microsoft 365 (Exchange Online). The server only sends website mail for the domain. */
    case Microsoft365 = 'microsoft365';

    /** Mail is handled elsewhere and the panel leaves all mail records alone. */
    case External = 'external';

    public function label(): string
    {
        return 'dns-mail::dns-mail.provider.' . $this->value;
    }

    public function hasLocalMailboxes(): bool
    {
        return $this === self::Rhis;
    }
}
