<?php

namespace App\Http\Controllers\WpHardening;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use App\Services\WpHardening\Hardening;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;

/** Security hardening of one WordPress install, shared by admins and customers. */
abstract class HardeningController extends Controller
{
    /** client | admin: route names and layout. */
    protected string $area = 'client';

    public function __construct(protected readonly Hardening $hardening) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    public function show(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $status = null;
        $error = null;
        try {
            $status = $this->hardening->status($install);
        } catch (AgentException $e) {
            $error = $e->getMessage();
        }

        return view('wp-hardening.show', [
            'area' => $this->area, 'install' => $install, 'status' => $status, 'agentError' => $error,
            'definitions' => Hardening::definitions(),
        ]);
    }

    /** One measure on or off. */
    public function update(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'measure' => ['required', Rule::in(Hardening::MEASURES)],
            'enabled' => ['required', 'boolean'],
        ]);
        $on = (bool) $data['enabled'];
        $title = __(Hardening::definitions()[$data['measure']]['title']);

        return $this->run($install, fn () => $this->hardening->apply($install, [$data['measure'] => $on]), 'wp_hardening.applied',
            [$data['measure'] => $on], $on ? __('Switched on: :measure.', ['measure' => $title]) : __('Switched off: :measure.', ['measure' => $title]));
    }

    public function all(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);

        return $this->run($install, fn () => $this->hardening->hardenAll($install), 'wp_hardening.applied',
            array_fill_keys(Hardening::MEASURES, true), __('All security measures are on.'));
    }

    public function salts(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $undo = $request->boolean('undo');

        return $this->run($install, fn () => $this->hardening->salts($install, $undo),
            $undo ? 'wp_hardening.salts_restored' : 'wp_hardening.salts_refreshed', [],
            $undo ? __('The previous security keys are back. Sessions from before the renewal work again.')
                : __('New security keys are in place. Everybody, including you, has to log in again.'));
    }

    private function run(WordPressInstall $install, \Closure $call, string $event, array $props, string $message): RedirectResponse
    {
        $back = redirect()->route($this->area.'.wp-hardening.show', $install);
        try {
            $call();
        } catch (AgentException $e) {
            return $back->withErrors(['agent' => __('The server did not make this change: :message', ['message' => $e->getMessage()])]);
        }
        ActivityLog::record($event, $install->label(), $props, $install->website->customer_id);

        return $back->with('status', $message);
    }
}
