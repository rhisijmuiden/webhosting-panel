<?php

declare(strict_types=1);

namespace Rhis\DnsMail\ClientSetup;

use InvalidArgumentException;

/**
 * Server settings a mail app needs. Login is always the full address.
 * Only TLS ports are offered: IMAPS 993, SMTP submissions 465 and submission 587 (STARTTLS).
 */
final readonly class MailClientSettings
{
    public function __construct(
        public string $email,
        public string $host,
        public string $displayName = '',
        public string $brandName = 'RHIS',
        public int $imapPort = 993,
        public int $smtpPort = 465,
        public int $submissionPort = 587,
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }
    }

    public function domain(): string
    {
        return strtolower(substr(strrchr($this->email, '@') ?: '@', 1));
    }

    /** @return array<string, array<string, string|int>> for a "manual setup" card in the panel */
    public function manual(): array
    {
        return [
            'incoming' => ['protocol' => 'IMAP', 'host' => $this->host, 'port' => $this->imapPort, 'security' => 'SSL/TLS', 'username' => $this->email],
            'outgoing' => ['protocol' => 'SMTP', 'host' => $this->host, 'port' => $this->smtpPort, 'security' => 'SSL/TLS', 'username' => $this->email],
            'outgoing_alt' => ['protocol' => 'SMTP', 'host' => $this->host, 'port' => $this->submissionPort, 'security' => 'STARTTLS', 'username' => $this->email],
        ];
    }
}
