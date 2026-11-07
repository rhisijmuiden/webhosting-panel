<?php

declare(strict_types=1);

namespace Rhis\Waf\Http;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\Waf\Models\WafEvent;
use Rhis\Waf\Waf;

/** Mode and exceptions of one website, shared by the admin and the client controller. */
trait ManagesExceptions
{
    private function saveMode(Request $request, Website $website, bool $allowDefault): RedirectResponse
    {
        $options = $allowDefault ? ['default', ...Waf::MODES] : Waf::MODES;
        $mode = (string) $request->validate(['mode' => ['required', 'in:'.implode(',', $options)]])['mode'];
        $row = $this->waf->forSite($website);
        $row->mode = $mode === 'default' ? null : $mode;
        $row->save();
        ActivityLog::record('waf.site_mode', $website->domain, ['mode' => $mode], $website->customer_id);

        return $this->applied(__('Web application firewall of :domain: :mode.', ['domain' => $website->domain, 'mode' => self::modeLabel($this->waf->modeFor($row))]));
    }

    private function storeException(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate(Waf::exceptionRules());
        $row = $this->waf->forSite($website);
        $list = $row->exceptions ?? [];
        if (count($list) >= (int) config('waf.max_site_exceptions')) {
            return back()->withErrors(['rule' => __('A website can have at most :max exceptions.', ['max' => config('waf.max_site_exceptions')])]);
        }
        $row->exceptions = Waf::addTo($list, Waf::exception((int) $data['rule'], $data['path'] ?? null, $data['param'] ?? null, $data['comment'] ?? null));
        $row->save();
        ActivityLog::record('waf.site_exception_added', $website->domain, ['rule' => (int) $data['rule'], 'path' => $data['path'] ?? null, 'param' => $data['param'] ?? null], $website->customer_id);

        return $this->applied(__('Exception for rule :rule added.', ['rule' => $data['rule']]));
    }

    private function destroyException(Website $website, int $index): RedirectResponse
    {
        $row = $this->waf->forSite($website);
        $list = $row->exceptions ?? [];
        abort_unless(isset($list[$index]), 404);
        $removed = $list[$index];
        unset($list[$index]);
        $row->exceptions = array_values($list);
        $row->save();
        ActivityLog::record('waf.site_exception_removed', $website->domain, ['rule' => $removed['rule']], $website->customer_id);

        return $this->applied(__('Exception for rule :rule removed.', ['rule' => $removed['rule']]));
    }

    /** "This was not an attack": exceptions for the chosen rules of an event, for that page or the whole site. */
    private function allowFromEvent(Request $request, WafEvent $event, array $scopes): RedirectResponse
    {
        $ids = collect($event->rules)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['integer', 'in:'.implode(',', $ids ?: [0])],
            'scope' => ['required', 'in:'.implode(',', $scopes)],
        ]);
        $path = $data['scope'] === 'page' ? $event->path() : null;
        if (strlen((string) $path) > 200 || ($path !== null && ! preg_match('#^/[A-Za-z0-9._~%/+:@=,-]*$#', $path))) {
            $path = null;
            $data['scope'] = 'site';
        }
        $comment = __('Allowed from event of :date', ['date' => $event->occurred_at->isoFormat('D MMM YYYY HH:mm')]);

        if ($data['scope'] === 'server') {
            $list = $this->waf->exceptions();
            foreach ($data['rules'] as $rule) {
                $list = Waf::addTo($list, Waf::exception((int) $rule, null, null, $comment));
            }
            $this->waf->saveExceptions($list);
            ActivityLog::record('waf.exception_added', null, ['rules' => $data['rules'], 'from_event' => $event->id]);
        } else {
            abort_if($event->website === null, 404);
            $row = $this->waf->forSite($event->website);
            $list = $row->exceptions ?? [];
            foreach ($data['rules'] as $rule) {
                $list = Waf::addTo($list, Waf::exception((int) $rule, $path, null, $comment));
            }
            $row->exceptions = array_slice($list, 0, (int) config('waf.max_site_exceptions'));
            $row->save();
            ActivityLog::record('waf.site_exception_added', $event->website->domain, ['rules' => $data['rules'], 'path' => $path, 'from_event' => $event->id], $event->website->customer_id);
        }

        return $this->applied(trans_choice('Rule :rules is no longer applied there.|Rules :rules are no longer applied there.', count($data['rules']), ['rules' => implode(', ', $data['rules'])]));
    }

    private function applied(string $message): RedirectResponse
    {
        try {
            $this->waf->apply();
        } catch (AgentException) {
            return back()->with('status', $message)
                ->withErrors(['agent' => __('Saved, but the server could not apply it right now. It is sent again automatically within five minutes.')]);
        }

        return back()->with('status', $message);
    }

    public static function modeLabel(string $mode): string
    {
        return ['block' => __('Blocking attacks'), 'detect' => __('Detect only'), 'off' => __('Off')][$mode] ?? $mode;
    }
}
