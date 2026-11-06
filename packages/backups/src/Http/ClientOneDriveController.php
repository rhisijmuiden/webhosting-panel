<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\Backups\Models\ClientBackupTarget;
use Rhis\Backups\OneDrive;
use RuntimeException;

/**
 * "Connect with Microsoft" from the location form. The form is sent here, kept
 * in the session while the customer signs in at Microsoft, and filled in again
 * with the connection when they come back.
 */
final class ClientOneDriveController extends Controller
{
    private const SECRETS = ['password', 'ssh_key', '_token', '_method'];

    public function __construct(private readonly OneDrive $onedrive) {}

    public function connect(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        abort_unless($this->onedrive->configured(), 404);
        $target = $request->integer('target') ?: null;
        if ($target) {
            ClientBackupTarget::where('customer_id', $customer->id)->findOrFail($target);
        }
        $auth = $this->onedrive->authorize();
        $request->session()->put('backups.onedrive.pending', [
            'state' => $auth['state'], 'verifier' => $auth['verifier'], 'customer_id' => $customer->id, 'target' => $target,
            'form' => ['type' => 'onedrive'] + $request->except([...self::SECRETS, 'target']),
        ]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $pending = $request->session()->pull('backups.onedrive.pending');
        abort_unless(is_array($pending) && $pending['customer_id'] === $customer->id, 404);
        $back = $pending['target']
            ? redirect()->route('client.backups.locations.edit', $pending['target'])
            : redirect()->route('client.backups.locations.create');
        $back->withInput($pending['form']);

        if (! hash_equals($pending['state'], (string) $request->query('state'))) {
            return $back->withErrors(['agent' => __('The answer from Microsoft does not match. Try again.')]);
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->withErrors(['agent' => __('The connection with Microsoft was cancelled.')]);
        }
        try {
            $connection = $this->onedrive->connect((string) $request->query('code'), $pending['verifier']);
        } catch (RuntimeException $e) {
            return $back->withErrors(['agent' => __('Could not connect OneDrive: :message', ['message' => $e->getMessage()])]);
        }
        $request->session()->put('backups.onedrive.connection', $connection + ['customer_id' => $customer->id]);

        return $back->with('status', __('Connected with OneDrive of :account. Save the location to finish.', ['account' => $connection['account']]));
    }

    private function customer(Request $request)
    {
        abort_unless(config('backups.customer_targets'), 404);
        $customer = $request->user()->customer;
        abort_unless($customer !== null, 404);

        return $customer;
    }
}
