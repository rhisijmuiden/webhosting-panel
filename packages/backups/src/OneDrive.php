<?php

declare(strict_types=1);

namespace Rhis\Backups;

use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Connect with Microsoft" for OneDrive backup locations. The panel uses one
 * Microsoft app (registered once by the admin in Microsoft Entra); customers
 * sign in with their own Microsoft account and grant access to their OneDrive.
 * The token is stored in the rclone format the agent hands to rclone.
 */
final class OneDrive
{
    public const LOGIN = 'https://login.microsoftonline.com/common/oauth2/v2.0';

    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    public const SCOPES = 'offline_access User.Read Files.ReadWrite';

    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return (string) $this->settings->get('backups.onedrive.client_id', config('backups.onedrive.client_id', ''));
    }

    private function clientSecret(): string
    {
        return (string) $this->settings->get('backups.onedrive.client_secret', config('backups.onedrive.client_secret', ''));
    }

    public function save(string $clientId, ?string $secret): void
    {
        $this->settings->set('backups.onedrive.client_id', $clientId);
        if (filled($secret)) {
            $this->settings->set('backups.onedrive.client_secret', $secret, encrypted: true);
        }
    }

    public function forget(): void
    {
        $this->settings->forget('backups.onedrive.client_id');
        $this->settings->forget('backups.onedrive.client_secret');
    }

    /** The address to register as redirect URI in Microsoft Entra. */
    public function redirectUri(): string
    {
        return route('client.backups.onedrive.callback');
    }

    /** What rclone needs to renew the token with the same app. */
    public function agentCredentials(): array
    {
        return $this->configured() ? ['client_id' => $this->clientId(), 'client_secret' => $this->clientSecret()] : [];
    }

    /** @return array{url: string, state: string, verifier: string} */
    public function authorize(?string $loginHint = null): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = array_filter([
            'client_id' => $this->clientId(), 'response_type' => 'code', 'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query', 'scope' => self::SCOPES, 'state' => $state, 'prompt' => 'select_account',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'login_hint' => $loginHint,
        ]);

        return ['url' => self::LOGIN.'/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Trade the code for a token and find the user's drive.
     *
     * @return array{token: string, drive_id: string, drive_type: string, account: string}
     */
    public function connect(string $code, string $verifier): array
    {
        try {
            $response = Http::asForm()->timeout(20)->post(self::LOGIN.'/token', [
                'client_id' => $this->clientId(), 'client_secret' => $this->clientSecret(), 'grant_type' => 'authorization_code',
                'code' => $code, 'redirect_uri' => $this->redirectUri(), 'code_verifier' => $verifier, 'scope' => self::SCOPES,
            ]);
            if (! $response->successful() || ! $response->json('refresh_token')) {
                throw new RuntimeException((string) ($response->json('error_description') ?: __('Microsoft did not give access.')));
            }
            $access = (string) $response->json('access_token');
            $graph = Http::withToken($access)->timeout(20)->acceptJson();
            $drive = $graph->get(self::GRAPH.'/me/drive', ['$select' => 'id,driveType']);
            $me = $graph->get(self::GRAPH.'/me', ['$select' => 'displayName,mail,userPrincipalName']);
        } catch (ConnectionException) {
            throw new RuntimeException(__('Microsoft cannot be reached right now. Try again later.'));
        }
        if (! $drive->successful() || ! $drive->json('id')) {
            throw new RuntimeException(__('This Microsoft account has no OneDrive.'));
        }
        $type = (string) $drive->json('driveType');

        return [
            'token' => json_encode([
                'access_token' => $access, 'token_type' => (string) ($response->json('token_type') ?: 'Bearer'),
                'refresh_token' => (string) $response->json('refresh_token'),
                'expiry' => now()->addSeconds((int) $response->json('expires_in', 3600))->utc()->format('Y-m-d\TH:i:s\Z'),
            ], JSON_UNESCAPED_SLASHES),
            'drive_id' => (string) $drive->json('id'),
            'drive_type' => in_array($type, ['personal', 'business', 'documentLibrary'], true) ? $type : 'business',
            'account' => (string) ($me->json('mail') ?: $me->json('userPrincipalName') ?: $me->json('displayName') ?: ''),
        ];
    }
}
