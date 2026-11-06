<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Console;

use Illuminate\Console\Command;
use Rhis\DnsMail\Services\DaneSync;
use Rhis\DnsMail\Services\DnsMailConfig;
use Throwable;

/** Daily: keep the TLSA record equal to the certificate Postfix uses. */
class DaneCommand extends Command
{
    protected $signature = 'dns-mail:dane';

    protected $description = 'Publish the DANE (TLSA) record of the mail host when it is switched on';

    public function handle(DnsMailConfig $config, DaneSync $dane): int
    {
        if (!$config->daneEnabled() || !$config->hasCloudflare()) {
            return self::SUCCESS;
        }
        try {
            $plan = $dane->apply(true);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(count($plan->changes) . ' change(s), ' . count($plan->conflicts) . ' conflict(s)');

        return $plan->conflicts === [] ? self::SUCCESS : self::FAILURE;
    }
}
