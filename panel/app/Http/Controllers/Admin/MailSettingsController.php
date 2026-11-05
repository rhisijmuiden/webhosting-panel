<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\ActivityLog;
use App\Services\MailConfigurator;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** The SMTP server the panel sends its own mail through (notifications, password resets). */
class MailSettingsController extends Controller
{
    public function __construct(private readonly MailConfigurator $mail, private readonly Settings $settings) {}

    public function edit(): View
    {
        $settings = $this->mail->settings();
        $settings['has_password'] = $settings['password'] !== '';
        unset($settings['password']);

        return view('admin.settings.mail', ['settings' => $settings, 'encryptions' => MailConfigurator::ENCRYPTIONS]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        foreach (['enabled', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'ehlo_domain', 'verify_peer', 'timeout'] as $key) {
            $this->settings->set("mail.{$key}", is_bool($data[$key]) ? (int) $data[$key] : $data[$key]);
        }
        if ($request->boolean('clear_password')) {
            $this->settings->set('mail.password', '', encrypted: true);
        } elseif (filled($data['password'] ?? null)) {
            $this->settings->set('mail.password', $data['password'], encrypted: true);
        }
        ActivityLog::record('settings.mail_updated', $data['host'] ?: 'local');

        return back()->with('status', __('Mail settings saved.'));
    }

    public function test(Request $request): RedirectResponse
    {
        $request->validate(['test_to' => ['required', 'email']]);
        try {
            $this->mail->apply(null, 'panel_test');
            Mail::mailer('panel_test')->to($request->input('test_to'))->send(new TestMail);
        } catch (Throwable $e) {
            return back()->withErrors(['test_to' => __('Sending failed: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('settings.mail_tested', $request->input('test_to'));

        return back()->with('status', __('Test mail sent to :address.', ['address' => $request->input('test_to')]));
    }

    private function validated(Request $request): array
    {
        $enabled = $request->boolean('enabled');
        $data = $request->validate([
            'host' => [$enabled ? 'required' : 'nullable', 'string', 'max:190'],
            'port' => [$enabled ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(MailConfigurator::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'ehlo_domain' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'],
            'timeout' => ['nullable', 'integer', 'min:3', 'max:120'],
        ]);

        return array_merge(['host' => '', 'port' => 587, 'username' => '', 'from_name' => '', 'ehlo_domain' => '', 'timeout' => 15], array_filter($data, fn ($v) => $v !== null), [
            'enabled' => $enabled,
            'verify_peer' => $request->boolean('verify_peer'),
        ]);
    }
}
