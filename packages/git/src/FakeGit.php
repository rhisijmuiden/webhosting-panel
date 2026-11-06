<?php

declare(strict_types=1);

namespace Rhis\Git;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Str;

/** Answers for git.* when the panel runs without a real agent (WHP_AGENT_DRIVER=fake, local development). */
final class FakeGit
{
    public static function register(FakeAgentClient $agent): void
    {
        $sha = fn (string $seed) => sha1($seed);
        $agent->respond('git.key', fn ($p) => [
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.substr(base64_encode(hash('sha256', ($p['id'] ?? '').($p['rotate'] ?? ''), true)), 0, 43).' deploy@'.($p['domain'] ?? 'example.test'),
            'fingerprint' => 'SHA256:'.substr(base64_encode(hash('sha256', $p['id'] ?? '', true)), 0, 43),
        ]);
        $agent->respond('git.check', fn () => ['branches' => [['name' => 'main', 'commit' => $sha('main')], ['name' => 'develop', 'commit' => $sha('develop')]], 'default' => 'main']);
        $agent->respond('git.fetch', fn ($p) => ['latest' => ['commit' => $sha($p['branch'] ?? 'main'), 'short' => substr($sha($p['branch'] ?? 'main'), 0, 7), 'message' => 'Update homepage', 'author' => 'Developer', 'date' => time()], 'deployed' => null]);
        $agent->respond('git.deploy', fn () => ['job' => (string) Str::uuid()]);
        $agent->respond('git.status', fn ($p) => [
            'deployed' => null, 'deployed_at' => null, 'has_key' => true, 'path_is_link' => true,
            'current_release' => now()->subMinutes(12)->format('YmdHis').'-'.substr($sha('3'), 0, 7),
            'releases' => collect([[12, '3'], [26 * 60, '2'], [3 * 24 * 60, '1']])->map(fn ($r, $i) => [
                'name' => now()->subMinutes($r[0])->format('YmdHis').'-'.substr($sha($r[1]), 0, 7), 'commit' => substr($sha($r[1]), 0, 7),
                'current' => $i === 0, 'created' => now()->subMinutes($r[0])->timestamp,
            ])->all(),
        ]);
        $agent->respond('git.rollback', fn ($p) => ['release' => $p['release'], 'log' => ['Switched '.$p['path'].' to release '.$p['release']]]);
        $agent->respond('git.remove', fn ($p) => ['removed' => $p['id'], 'files_kept' => true]);
        $agent->respond('git.tools', ['git' => true, 'composer' => true, 'npm' => true, 'node' => true]);
    }
}
