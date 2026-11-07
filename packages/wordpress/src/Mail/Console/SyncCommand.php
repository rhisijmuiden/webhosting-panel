<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\WordPress\Mail\WebsiteMail;
use Rhis\WordPress\Mail\WpMailSetting;
use Rhis\WordPress\Models\WordPressInstall;

/** New WordPress installs send mail through this server (see config wp-mail.auto). */
final class SyncCommand extends Command
{
    protected $signature = 'whp:wordpress:mail-sync {--limit=50 : Installs per run}';

    protected $description = 'Switch new WordPress installs over to website mail through this server';

    public function handle(WebsiteMail $mail): int
    {
        $installs = WordPressInstall::with('website')
            ->whereHas('website', fn ($q) => $q->where('status', 'active'))
            ->where(fn ($q) => $q->whereNotIn('id', WpMailSetting::select('wordpress_install_id'))
                ->orWhereIn('id', WpMailSetting::select('wordpress_install_id')->where('reason', 'unavailable')->where('enabled', false)))
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        foreach ($installs as $install) {
            try {
                $s = $mail->adopt($install);
                $this->line(sprintf('%s: %s', $install->label(), $s->enabled ? 'on' : 'off ('.($s->reason ?? 'chosen').')'));
            } catch (AgentException $e) {
                $this->warn($install->label().': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
