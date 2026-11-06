<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts an Adaptive Card to a Microsoft Teams channel.
 *
 * Use a "Workflows" webhook in Teams (channel > ... > Workflows > "Post to a channel
 * when a webhook request is received"); Microsoft retired the old Office 365
 * connectors. The same message format also works for the older webhook.office.com URLs.
 */
final class Teams
{
    /** @param list<array{severity: string, type: string, headline: string, detail: ?string, when: string}> $items */
    public static function card(string $title, string $intro, array $items, string $url, string $button): array
    {
        $body = [
            ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Large', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $intro, 'wrap' => true, 'isSubtle' => true, 'spacing' => 'Small'],
        ];
        foreach ($items as $item) {
            $color = match (true) {
                $item['type'] === 'recovery' => 'Good',
                $item['severity'] === 'critical' => 'Attention',
                default => 'Warning',
            };
            $body[] = [
                'type' => 'Container', 'separator' => true, 'spacing' => 'Medium',
                'items' => array_values(array_filter([
                    ['type' => 'TextBlock', 'text' => $item['badge'].' · '.$item['headline'], 'weight' => 'Bolder', 'color' => $color, 'wrap' => true],
                    $item['detail'] ? ['type' => 'TextBlock', 'text' => $item['detail'], 'wrap' => true, 'spacing' => 'None'] : null,
                    ['type' => 'TextBlock', 'text' => $item['when'], 'isSubtle' => true, 'size' => 'Small', 'wrap' => true, 'spacing' => 'None'],
                ])),
            ];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => $button, 'url' => $url]],
                ],
            ]],
        ];
    }

    public static function post(string $webhook, array $card): void
    {
        if (! self::validUrl($webhook)) {
            throw new RuntimeException('The Teams webhook must be an https URL.');
        }
        $res = Http::timeout(15)->connectTimeout(5)->acceptJson()->post($webhook, $card);
        if (! $res->successful()) {
            throw new RuntimeException('Teams answered HTTP '.$res->status().': '.mb_substr(trim($res->body()), 0, 200));
        }
    }

    /** https only, and never an address inside this server or network. */
    public static function validUrl(string $url): bool
    {
        if (! str_starts_with($url, 'https://') || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return (bool) filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
