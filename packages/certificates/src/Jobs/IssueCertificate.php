<?php

declare(strict_types=1);

namespace Rhis\Certificates\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\Certificates\CertificateManager;
use Rhis\Certificates\Models\SiteCertificate;

/** Requests a Let's Encrypt certificate (normal or wildcard through Cloudflare DNS-01) on the server. */
final class IssueCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $certificateId) {}

    public function handle(CertificateManager $manager): void
    {
        $record = SiteCertificate::find($this->certificateId);
        if ($record) {
            $manager->run($record);
        }
    }
}
