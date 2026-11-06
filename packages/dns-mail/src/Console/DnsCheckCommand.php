<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Console;

use Illuminate\Console\Command;
use Rhis\DnsMail\Services\DnsOnboarder;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\ExternalDnsCheck;

/** Every few minutes: nameserver checks for the DNS wizard, and checks of domains with DNS elsewhere. */
class DnsCheckCommand extends Command
{
    protected $signature = 'dns-mail:check';

    protected $description = 'Check pending nameserver changes and the DNS of domains hosted elsewhere';

    public function handle(DnsOnboarder $onboarder, ExternalDnsCheck $external, DnsMailConfig $config): int
    {
        $pending = $config->hasCloudflare() ? $onboarder->checkDue() : 0;
        $elsewhere = $external->checkDue();
        $this->info("Nameserver checks: {$pending}, external DNS checks: {$elsewhere}");

        return self::SUCCESS;
    }
}
