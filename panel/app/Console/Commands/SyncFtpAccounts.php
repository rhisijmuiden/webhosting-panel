<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\FtpAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Sends every FTP account to the server again (ProFTPD virtual users). */
class SyncFtpAccounts extends Command
{
    protected $signature = 'whp:ftp:sync {--pending : Only when an earlier change did not reach the server}';

    protected $description = 'Write the extra FTP/SFTP accounts to the server';

    public function handle(FtpAccounts $ftp): int
    {
        if ($this->option('pending') && ! Cache::get('ftp-accounts.pending')) {
            return self::SUCCESS;
        }
        try {
            $result = $ftp->sync();
        } catch (AgentException $e) {
            Cache::forever('ftp-accounts.pending', true);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        Cache::forget('ftp-accounts.pending');
        $this->info(sprintf('%d FTP accounts written, %d left out.', $result['accounts'] ?? 0, count($result['skipped'] ?? [])));

        return self::SUCCESS;
    }
}
