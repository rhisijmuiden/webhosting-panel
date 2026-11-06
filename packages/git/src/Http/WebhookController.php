<?php

declare(strict_types=1);

namespace Rhis\Git\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rhis\Git\Deployer;
use Rhis\Git\Models\GitRepository;

/**
 * Push webhooks from GitHub, GitLab, Bitbucket, Gitea/Forgejo or any other server.
 *
 * Proof that the call comes from the repository owner, checked in constant time:
 * - GitHub, Bitbucket, Gitea: HMAC-SHA256 of the body with the secret (X-Hub-Signature-256 / X-Hub-Signature / X-Gitea-Signature)
 * - GitLab: the secret itself in X-Gitlab-Token
 * - anything else: the secret in X-Webhook-Token or ?token=
 */
final class WebhookController extends Controller
{
    public function __construct(private readonly Deployer $deployer) {}

    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        $repo = preg_match('/^[0-9a-f-]{36}$/', $uuid) ? GitRepository::with('website')->where('uuid', $uuid)->first() : null;
        if (! $repo || ! $repo->website || $repo->website->status === 'suspended') {
            return response()->json(['error' => 'not_found'], 404);
        }
        if (! self::verify($request, (string) $repo->webhook_secret)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }
        $repo->forceFill(['last_webhook_at' => now()])->save();

        $event = self::event($request);
        if ($event === 'ping') {
            return response()->json(['ok' => true, 'message' => 'pong']);
        }
        if ($event !== 'push') {
            return response()->json(['ok' => true, 'ignored' => 'event']);
        }
        // GitHub can also send the payload form encoded (payload=<json>).
        $payload = $request->isJson() ? $request->json()->all() : (json_decode((string) $request->input('payload', ''), true) ?: []);
        $push = self::push(is_array($payload) ? $payload : []);
        if ($push['branch'] !== null && $push['branch'] !== $repo->branch) {
            return response()->json(['ok' => true, 'ignored' => 'branch', 'branch' => $repo->branch]);
        }
        if ($push['deleted']) {
            return response()->json(['ok' => true, 'ignored' => 'deleted']);
        }
        if ($push['commit']) {
            $repo->update(['latest_commit' => $push['commit']]);
        }
        if (! $repo->auto_deploy) {
            return response()->json(['ok' => true, 'deploy' => false]);
        }
        $deployment = $this->deployer->deploy($repo, 'webhook', $push['pusher']);

        return response()->json(['ok' => true, 'deploy' => true, 'deployment' => $deployment->id, 'status' => $deployment->status], 202);
    }

    public static function verify(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        $body = $request->getContent();
        $hmac = hash_hmac('sha256', $body, $secret);
        foreach (['X-Hub-Signature-256', 'X-Hub-Signature'] as $h) {
            if (($sig = (string) $request->header($h)) !== '' && str_starts_with($sig, 'sha256=')) {
                return hash_equals('sha256='.$hmac, $sig);
            }
        }
        if (($sig = (string) $request->header('X-Gitea-Signature', $request->header('X-Gogs-Signature', ''))) !== '') {
            return hash_equals($hmac, $sig);
        }
        foreach ([$request->header('X-Gitlab-Token'), $request->header('X-Webhook-Token'), $request->query('token')] as $token) {
            if (is_string($token) && $token !== '') {
                return hash_equals($secret, $token);
            }
        }

        return false;
    }

    /** push, ping or something else, from the provider's event header. */
    public static function event(Request $request): string
    {
        $e = strtolower((string) ($request->header('X-GitHub-Event') ?? $request->header('X-Gitea-Event')
            ?? $request->header('X-Gogs-Event') ?? $request->header('X-Gitlab-Event') ?? $request->header('X-Event-Key') ?? 'push'));

        return match (true) {
            in_array($e, ['ping', 'diagnostics:ping'], true) => 'ping',
            in_array($e, ['push', 'push hook', 'repo:push'], true) => 'push',
            default => $e,
        };
    }

    /** Branch, newest commit and pusher from a push payload of any of the providers. */
    public static function push(array $p): array
    {
        $branch = null;
        $commit = null;
        $deleted = false;
        if (isset($p['push']['changes'])) { // Bitbucket
            $change = collect($p['push']['changes'])->last();
            $new = $change['new'] ?? null;
            $deleted = $new === null;
            $ref = $new ?? $change['old'] ?? [];
            $branch = ($ref['type'] ?? 'branch') === 'branch' ? ($ref['name'] ?? null) : '';
            if ($new && isset($new['target']['hash'])) {
                $commit = self::commit($new['target']['hash'], $new['target']['message'] ?? '', $new['target']['author']['user']['display_name'] ?? ($new['target']['author']['raw'] ?? ''), $new['target']['date'] ?? null);
            }
            $pusher = $p['actor']['display_name'] ?? null;
        } else { // GitHub, GitLab, Gitea and most others
            $ref = (string) ($p['ref'] ?? '');
            $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : ($ref === '' ? null : '');
            $after = (string) ($p['after'] ?? $p['checkout_sha'] ?? '');
            $deleted = (bool) ($p['deleted'] ?? false) || preg_match('/^0{40}$/', $after) === 1;
            $head = $p['head_commit'] ?? collect($p['commits'] ?? [])->last();
            if ($after !== '' && ! $deleted) {
                $commit = self::commit($after, $head['message'] ?? '', $head['author']['name'] ?? '', $head['timestamp'] ?? null);
            }
            $pusher = $p['pusher']['name'] ?? $p['pusher']['login'] ?? $p['user_name'] ?? $p['sender']['login'] ?? null;
        }

        return ['branch' => $branch, 'commit' => $commit, 'deleted' => $deleted, 'pusher' => $pusher ? mb_substr((string) $pusher, 0, 100) : null];
    }

    private static function commit(string $sha, string $message, string $author, ?string $date): ?array
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return null;
        }

        return [
            'commit' => $sha, 'short' => substr($sha, 0, 7), 'message' => mb_substr(strtok($message, "\n") ?: '', 0, 300),
            'author' => mb_substr($author, 0, 100), 'date' => $date ? strtotime($date) ?: null : null,
        ];
    }
}
