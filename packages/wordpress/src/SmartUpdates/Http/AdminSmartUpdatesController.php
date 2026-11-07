<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Http;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;

final class AdminSmartUpdatesController extends SmartUpdatesController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_mode' => ['required', Rule::in(UpdatePolicy::MODES)],
            'threshold' => ['required', 'integer', 'min:1', 'max:90'],
            'visual' => ['sometimes', 'boolean'],
            'concurrency' => ['required', 'integer', 'min:1', 'max:6'],
            'customer_policy' => ['sometimes', 'boolean'],
            'admin_notify' => ['sometimes', 'boolean'],
            'admin_mail_to' => ['nullable', 'string', 'max:500'],
        ]);
        foreach (array_filter(array_map('trim', explode(',', (string) ($data['admin_mail_to'] ?? '')))) as $mail) {
            if (! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                return back()->withInput()->withErrors(['admin_mail_to' => __(':mail is not a valid email address.', ['mail' => $mail])]);
            }
        }
        foreach (['visual', 'customer_policy', 'admin_notify'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }
        foreach ($data as $key => $value) {
            $this->options->set($key, $value ?? '');
        }
        ActivityLog::record('wordpress.smart_settings', null, ['default_mode' => $data['default_mode'], 'threshold' => $data['threshold']]);

        return redirect($this->route('index', ['tab' => 'settings']))->with('status', __('Settings saved.'));
    }
}
