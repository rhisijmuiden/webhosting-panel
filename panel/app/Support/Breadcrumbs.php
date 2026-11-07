<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The trail in the top bar. On a page that belongs to one website (the domain
 * page itself, WordPress tools, files, staging, certificates and so on) it shows
 * where you are and every step back:
 *
 *   Hosting services › Domains › customer › example.com › Plugins & themes
 *
 * The website is found from the route parameters: a Website, or a model with a
 * website() relation (a WordPress install, a Git repository, a cron job...).
 * Pages without a website keep the plain "section › menu item" crumbs.
 */
class Breadcrumbs
{
    /** Model classes whose page sits directly under the domain, so the small in-page trail repeats the top bar. */
    private const DIRECT = [Website::class, 'Rhis\\WordPress\\Models\\WordPressInstall'];

    public function __construct(private Menu $menu, private bool $isAdmin, private ?string $title = null) {}

    /**
     * The crumbs as a list of ['label' => string, 'url' => ?string], or null
     * when the page has no website. The last crumb is the current page.
     *
     * @return array{crumbs: list<array{label: string, url: ?string}>, direct: bool}|null
     */
    public function forSite(): ?array
    {
        [$site, $direct, $owner] = $this->site();
        if ($site === null) {
            return null;
        }

        $area = $this->isAdmin ? 'admin' : 'client';
        $crumbs = [];
        $list = $this->menu->item('websites');
        if ($list !== null) {
            if (($list['section_label'] ?? '') !== '' && $list['section_label'] !== $list['label']) {
                $crumbs[] = ['label' => __($list['section_label']), 'url' => $list['section_route'] ? route($list['section_route']) : null];
            }
            $crumbs[] = ['label' => __($list['label']), 'url' => route($list['route'])];
        }

        if ($this->isAdmin && $site->customer && Route::has('admin.customers.show')) {
            $crumbs[] = ['label' => $site->customer->displayName(), 'url' => route('admin.customers.show', $site->customer)];
        }

        $show = $list !== null && Route::has($area.'.websites.show');
        if ($site->getAttribute('parent_id') && $site->parent) {
            $crumbs[] = ['label' => $site->parent->domain, 'url' => $show ? route($area.'.websites.show', $site->parent) : null];
        }
        $crumbs[] = ['label' => $site->domain, 'url' => $show ? route($area.'.websites.show', $site) : null];

        if (! request()->routeIs($area.'.websites.show') && ($page = $this->pageLabel($site, $owner)) !== null) {
            $crumbs[] = ['label' => $page, 'url' => null];
        }

        // The current page is never a link.
        $crumbs[array_key_last($crumbs)]['url'] = null;

        return ['crumbs' => $crumbs, 'direct' => $direct];
    }

    /** @return array{0: ?Website, 1: bool, 2: ?Model} the website, whether the page sits directly under it, and the model it came from */
    private function site(): array
    {
        $route = request()->route();
        if ($route === null) {
            return [null, false, null];
        }
        foreach ($route->parameters() as $param) {
            if ($param instanceof Website) {
                return [$param, true, $param];
            }
        }
        foreach ($route->parameters() as $param) {
            if ($param instanceof Model && method_exists($param, 'website')) {
                $site = rescue(fn () => $param->website, null, false);
                if ($site instanceof Website) {
                    return [$site, in_array($param::class, self::DIRECT, true), $param];
                }
            }
        }

        return [null, false, null];
    }

    /**
     * What the current page is called: the first part of the page title that is
     * not the domain itself ("Plugins & themes · example.com" gives "Plugins &
     * themes"), or else the active menu item.
     */
    private function pageLabel(Website $site, ?Model $owner): ?string
    {
        $skip = [mb_strtolower($site->domain)];
        if ($owner !== null && method_exists($owner, 'label')) {
            $skip[] = mb_strtolower((string) rescue(fn () => $owner->label(), '', false));
        }

        foreach (preg_split('/\s+[·|—–]\s+/u', (string) $this->title) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array(mb_strtolower($part), $skip, true)) {
                return $part;
            }
        }

        $active = $this->menu->active();
        if ($active !== null && $active['key'] !== 'websites') {
            return __($active['label']);
        }

        return null;
    }
}
