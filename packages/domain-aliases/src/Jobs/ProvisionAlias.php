<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\DomainAliases\AliasManager;
use Rhis\DomainAliases\Models\DomainAlias;

/** Adds the alias on the server (Nginx + Let's Encrypt), then mail and DNS. */
final class ProvisionAlias implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public DomainAlias $alias, public bool $ssl = true) {}

    public function handle(AliasManager $manager): void
    {
        $manager->provision($this->alias, $this->ssl);
    }
}
