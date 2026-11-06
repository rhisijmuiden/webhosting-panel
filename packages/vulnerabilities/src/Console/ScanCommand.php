<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Console;

use App\Models\Website;
use Illuminate\Console\Command;
use Rhis\Vulnerabilities\Notifier;
use Rhis\Vulnerabilities\Scanner;

/** Check every website for vulnerable WordPress parts and malware, then mail the customers. */
final class ScanCommand extends Command
{
    protected $signature = 'whp:vuln:scan {domain? : Only this website} {--no-mail : Do not mail customers}';

    protected $description = 'Match WordPress core, plugins and themes against the vulnerability database, read the malware scans and mail customers';

    public function handle(Scanner $scanner, Notifier $notifier): int
    {
        $sites = Website::query()->where('status', 'active')
            ->when($this->argument('domain'), fn ($q, $d) => $q->where('domain', $d))->orderBy('domain')->get();
        $found = $resolved = 0;
        foreach ($sites as $site) {
            $r = $scanner->scanWebsite($site);
            $found += $r['found'];
            $resolved += $r['resolved'];
            foreach ($r['errors'] as $e) {
                $this->warn($e);
            }
        }
        $this->info("{$sites->count()} websites checked: {$found} new problems, {$resolved} resolved.");
        if (! $this->option('no-mail')) {
            $this->info($notifier->run().' customers mailed.');
        }

        return self::SUCCESS;
    }
}
