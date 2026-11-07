<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use App\Models\Website;
use Rhis\Certificates\Models\SiteCertificate;

/** Labels for certificates in views and mails. */
final class Present
{
    public static function source(?SiteCertificate $c): string
    {
        return match ($c?->source) {
            'letsencrypt' => __('Let\'s Encrypt'),
            'wildcard' => __('Wildcard (Let\'s Encrypt)'),
            'custom' => __('Own certificate'),
            'imported' => __('Taken over from the old server'),
            default => __('No certificate'),
        };
    }

    /** One line for the website page: what the website serves and until when. */
    public static function summary(Website $website): string
    {
        $c = SiteCertificate::where('website_id', $website->id)->first();
        if (! $c || $c->source === 'none') {
            return $website->ssl_status === 'active' ? __('Let\'s Encrypt, renewed automatically') : __('No valid certificate yet');
        }
        $until = $c->not_after ? __('valid until :date', ['date' => $c->not_after->format('d-m-Y')]) : null;

        return match ($c->source) {
            'letsencrypt' => __('Let\'s Encrypt, renewed automatically'),
            'wildcard' => __('Wildcard *.:domain, renewed automatically', ['domain' => $c->wildcard_of ?: $website->domain]),
            default => self::source($c).($until ? ', '.$until : ''),
        };
    }

    public static function tone(Website $website): string
    {
        $c = SiteCertificate::where('website_id', $website->id)->first();
        if (! $c || $c->source === 'none') {
            return $website->ssl_status === 'active' ? 'ok' : 'warn';
        }

        return $c->health();
    }
}
