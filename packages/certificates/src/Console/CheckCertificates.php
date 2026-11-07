<?php

declare(strict_types=1);

namespace Rhis\Certificates\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Certificates\CertificateManager;
use Rhis\Certificates\ExpiryWarnings;

/** Daily: read every website's certificate from the server and warn before one expires. */
final class CheckCertificates extends Command
{
    protected $signature = 'certificates:check {--no-mail : Only read the certificates}';

    protected $description = 'Read the SSL certificates of all websites and send expiry warnings';

    public function handle(CertificateManager $manager, ExpiryWarnings $warnings): int
    {
        try {
            $records = $manager->refreshAll();
            $manager->syncCloudflareToken();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $sent = $this->option('no-mail') ? 0 : $warnings->send($records);
        $this->info(sprintf('%d certificates read, %d warning mails sent.', $records->count(), $sent));

        return self::SUCCESS;
    }
}
