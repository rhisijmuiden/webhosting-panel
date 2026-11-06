<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use InvalidArgumentException;

/**
 * BIMI: the brand logo mail apps show next to a message (Gmail, Apple Mail, Yahoo).
 *
 * Mail apps only show it when DMARC is enforced (quarantine or reject at
 * 100%). Gmail and Apple also want a certificate for the logo (VMC or CMC),
 * given as $certificateUrl.
 */
final readonly class Bimi
{
    public function __construct(
        public string $logoUrl,
        public ?string $certificateUrl = null,
        public string $selector = 'default',
    ) {
        foreach (array_filter([$logoUrl, $certificateUrl]) as $url) {
            if (!preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/[^\s;"]*)?$#i', $url)) {
                throw new InvalidArgumentException("BIMI needs an https URL without spaces or semicolons: {$url}");
            }
        }
    }

    public function recordName(string $domain): string
    {
        return $this->selector . '._bimi.' . $domain;
    }

    public function txt(): string
    {
        return 'v=BIMI1; l=' . $this->logoUrl . '; a=' . ($this->certificateUrl ?? '');
    }

    /** Mail apps ignore BIMI unless DMARC is enforced for all mail. */
    public static function dmarcAllows(DmarcPolicy $dmarc): bool
    {
        return in_array($dmarc->policy, ['quarantine', 'reject'], true) && $dmarc->percentage === 100;
    }
}
