<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\PhpSettings;
use App\Services\WebsiteManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebsiteController extends Controller
{
    public function __construct(private readonly WebsiteManager $manager) {}

    public function index(Request $request): View
    {
        return view('client.websites.index', ['websites' => $request->user()->customer->websites()->orderBy('domain')->get()]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);

        return view('client.websites.show', compact('website'));
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'php_version' => ['sometimes', Rule::in(Website::PHP_VERSIONS)],
            'page_cache' => ['sometimes', 'boolean'],
        ]);
        try {
            if (isset($data['php_version']) && $data['php_version'] !== $website->php_version) {
                $this->manager->setPhp($website, $data['php_version']);
            }
            if (array_key_exists('page_cache', $data) && (bool) $data['page_cache'] !== $website->page_cache) {
                $this->manager->setCache($website, (bool) $data['page_cache']);
            }
        } catch (AgentException) {
            return back()->withErrors(['agent' => __('The change could not be applied. Please try again or contact support.')]);
        }

        return back()->with('status', __('Website saved.'));
    }

    public function php(Request $request, Website $website, PhpSettings $php): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate($php->siteRules(), [], collect(PhpSettings::KEYS)->mapWithKeys(fn ($k, $key) => ["php.$key" => __($k[0])])->all());
        try {
            $this->manager->setPhpSettings($website, $php->fromInput($data['php'] ?? []));
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => __('The change could not be applied. Please try again or contact support.')]);
        }

        return back()->with('status', __('PHP settings saved.'));
    }

    private function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
    }
}
