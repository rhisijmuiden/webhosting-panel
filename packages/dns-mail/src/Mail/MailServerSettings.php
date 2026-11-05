<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

/**
 * Facts about this hosting server that every domain's mail records use.
 * The panel fills these from its settings screen and the agent's system.info.
 */
final readonly class MailServerSettings
{
    /**
     * @param string $mailHost name clients and other servers connect to, e.g. "mail.rhis.nl"
     * @param list<string> $relaySpfIncludes SPF includes of the outbound relay (Postfix relayhost)
     */
    public function __construct(
        public string $mailHost,
        public ?string $ipv4 = null,
        public ?string $ipv6 = null,
        public array $relaySpfIncludes = [],
        public string $dkimSelector = 'whp',
        public ?string $webmailHost = null,
        public string $brandName = 'RHIS',
    ) {
    }

    /** SPF that covers mail this server sends (directly and through the relay). */
    public function spf(): SpfRecord
    {
        $spf = new SpfRecord();
        if ($this->ipv4) {
            $spf = $spf->withIp($this->ipv4);
        }
        if ($this->ipv6) {
            $spf = $spf->withIp($this->ipv6);
        }
        foreach ($this->relaySpfIncludes as $include) {
            $spf = $spf->withInclude($include);
        }

        return $spf;
    }
}
