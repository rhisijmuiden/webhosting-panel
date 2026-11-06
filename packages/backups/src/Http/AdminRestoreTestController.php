<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Backups\Models\RestoreTest;
use Rhis\Backups\RestoreTests\RestoreTester;
use Rhis\Backups\RestoreTests\RestoreTestOptions;

/** Admin: automatic restore tests of the server backups. */
final class AdminRestoreTestController extends Controller
{
    private const TARGET = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly RestoreTester $tester,
        private readonly RestoreTestOptions $options,
    ) {}

    public function index(): View
    {
        $running = $this->tester->running();
        if ($running) {
            $this->tester->collect($running);
            $running = $running->fresh()->isRunning() ? $running->fresh() : null;
        }
        $agentError = null;
        try {
            $targets = $this->agent->call('backup.targets', [], 30)['targets'] ?? [];
        } catch (AgentException $e) {
            $targets = [];
            $agentError = $e->getMessage();
        }
        $last = RestoreTest::whereIn('status', RestoreTest::FINISHED)->latest('id')->first();
        $lastOk = RestoreTest::where('status', 'ok')->latest('id')->first();
        $history = RestoreTest::with('user')->latest('id')->paginate(15);
        // Pass rate over the last 90 days, for the ring.
        $recent = RestoreTest::whereIn('status', RestoreTest::FINISHED)->where('started_at', '>=', now()->subDays(90))->get();

        return view('backups::admin.restore-tests.index', [
            'options' => $this->options->all(), 'next' => $this->options->nextSlot(), 'running' => $running,
            'last' => $last, 'lastOk' => $lastOk, 'history' => $history, 'targets' => $targets,
            'agentError' => $agentError, 'days' => RestoreTestOptions::DAYS,
            'passRate' => $recent->count() ? (int) round(100 * $recent->whereIn('status', ['ok', 'warning'])->count() / $recent->count()) : null,
            'recentCount' => $recent->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['target' => ['nullable', 'regex:'.self::TARGET]]);
        if ($this->tester->running()) {
            return back()->withErrors(['agent' => __('A restore test is already running.')]);
        }
        try {
            $run = $this->tester->start('manual', $request->user()->id, $data['target'] ?? null);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('backup.restore_test_started', $run->target, ['test' => $run->id]);

        return redirect()->route('admin.backups.restore-tests.show', $run)->with('status', __('Restore test started.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'day' => ['required', Rule::in(RestoreTestOptions::DAYS)],
            'monthday' => ['required', 'integer', 'min:1', 'max:28'],
            'time' => ['required', 'date_format:H:i'],
            'target' => ['nullable', 'regex:'.self::TARGET],
            'system' => ['boolean'],
            'sites' => ['required', 'integer', 'min:0', 'max:50'],
            'mail' => ['required', 'integer', 'min:0', 'max:50'],
            'databases' => ['required', 'integer', 'min:0', 'max:50'],
            'kinds' => ['required', Rule::in(['latest', 'both'])],
            'max_age_hours' => ['required', 'integer', 'min:0', 'max:2000'],
            'notify' => ['required', Rule::in(['problems', 'warnings', 'always', 'never'])],
            'mail_to' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $m) {
                    if (! filter_var($m, FILTER_VALIDATE_EMAIL)) {
                        $fail(__(':mail is not a valid email address.', ['mail' => $m]));
                    }
                }
            }],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['system'] = $request->boolean('system');
        $data['target'] = $data['target'] ?? '';
        $data['mail_to'] = $data['mail_to'] ?? '';
        $this->options->save($data);
        ActivityLog::record('backup.restore_test_settings', null, $data);

        return back()->with('status', __('Restore test settings saved.'));
    }

    public function show(RestoreTest $test): View
    {
        if ($test->isRunning()) {
            $test = $this->tester->collect($test)->fresh();
        }

        return view('backups::admin.restore-tests.show', ['run' => $test]);
    }
}
