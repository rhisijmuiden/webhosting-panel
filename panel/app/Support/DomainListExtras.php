<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * What the admin domain list shows next to each website that comes from
 * optional packages: domain aliases, WordPress, mail/DNS state. Each part is
 * skipped when its package is not installed.
 */
class DomainListExtras
{
    /**
     * @param  Collection<int, Website>  $websites
     * @return array<int, array{aliases: array, wordpress: ?int, mail: ?array}>
     */
    public static function for(Collection $websites): array
    {
        $ids = $websites->pluck('id')->all();
        $extras = array_fill_keys($ids, ['aliases' => [], 'wordpress' => null, 'mail' => null]);
        if ($ids === []) {
            return $extras;
        }

        $alias = 'Rhis\\DomainAliases\\Models\\DomainAlias';
        if (class_exists($alias)) {
            foreach (rescue(fn () => $alias::whereIn('website_id', $ids)->orderBy('domain')->get(), collect(), false) as $a) {
                $extras[$a->website_id]['aliases'][] = ['domain' => $a->domain, 'mode' => $a->mode, 'status' => $a->status];
            }
        }

        $wp = 'Rhis\\WordPress\\Models\\WordPressInstall';
        if (class_exists($wp) && Route::has('admin.wordpress.show')) {
            foreach (rescue(fn () => $wp::whereIn('website_id', $ids)->orderBy('path')->get(['id', 'website_id']), collect(), false) as $w) {
                $extras[$w->website_id]['wordpress'] ??= $w->id;
            }
        }

        $mail = 'Rhis\\DnsMail\\Models\\MailDomain';
        if (class_exists($mail)) {
            foreach (rescue(fn () => $mail::whereIn('website_id', $ids)->whereNull('alias_of_id')->get(['id', 'website_id', 'dns_status', 'dns_error']), collect(), false) as $m) {
                $extras[$m->website_id]['mail'] ??= ['status' => $m->dns_status, 'error' => $m->dns_error];
            }
        }

        return $extras;
    }
}
