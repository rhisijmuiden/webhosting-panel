<?php

declare(strict_types=1);

namespace Rhis\Migration\Http;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Services\Migrator;
use Throwable;

/**
 * Status of all migrations: progress per domain and the log, refreshed live
 * by the page through feed().
 */
final class StatusController extends Controller
{
    /** Order on the page: what is moving first, finished last. */
    private const ORDER = ['preparing' => 0, 'copying' => 0, 'syncing' => 0, 'queued' => 1, 'failed' => 2, 'copied' => 3, 'live' => 4, 'done' => 5];

    public function __construct(private readonly AgentClient $agent, private readonly Migrator $migrator) {}

    public function index(Request $request): View
    {
        $data = $this->collect($request);

        return view('migration::admin.status', $data + ['statusLabels' => self::statusLabels()]);
    }

    public function feed(Request $request): JsonResponse
    {
        $data = $this->collect($request);
        $labels = self::statusLabels();

        return response()->json([
            'counts' => $data['counts'],
            'items' => $data['rows']->map(fn (array $r) => [
                'id' => $r['item']->id,
                'status' => $r['item']->status,
                'label' => $labels[$r['item']->status] ?? $r['item']->status,
                'pill' => $r['item']->statusPill(),
                'stage' => $r['progress']['stage'],
                'percent' => $r['progress']['percent'],
                'step' => $r['progress']['step'],
                'updated' => $r['item']->updated_at?->diffForHumans(),
            ])->values(),
            'selected' => $data['selected']?->id,
            'active' => $data['selected'] ? $this->isMoving($data['selected']) : false,
            'log' => $data['log'],
        ]);
    }

    /** @return array{rows: Collection, counts: array<string, int>, selected: ?MigrationItem, log: list<array>, agentErrors: list<string>} */
    private function collect(Request $request): array
    {
        $errors = [];
        // The scheduler ticks every minute; while someone watches, pick up finished copies sooner.
        if (Cache::add('migration:status-tick', 1, 15)) {
            try {
                $this->migrator->tick();
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $items = MigrationItem::with('source')->get()
            ->sortBy(fn (MigrationItem $i) => [self::ORDER[$i->status] ?? 9, -($i->updated_at?->getTimestamp() ?? 0)])
            ->values();

        $jobs = [];
        foreach ($items as $item) {
            if ($item->agent_job && in_array($item->status, ['copying', 'syncing'], true)) {
                try {
                    $jobs[$item->id] = $this->agent->call('job.status', ['job' => $item->agent_job], 20);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        $rows = $items->map(fn (MigrationItem $i) => ['item' => $i, 'progress' => $i->progress($jobs[$i->id] ?? null), 'job' => $jobs[$i->id] ?? null]);

        $selected = $request->filled('item') ? $items->firstWhere('id', (int) $request->query('item')) : null;
        $selected ??= $items->first(fn (MigrationItem $i) => $this->isMoving($i)) ?? $items->first();

        $log = [];
        if ($selected) {
            $log = $selected->logView($jobs[$selected->id] ?? null);
        }

        $counts = [
            'running' => $items->filter(fn ($i) => $i->isActive())->count(),
            'queued' => $items->where('status', 'queued')->count(),
            'copied' => $items->where('status', 'copied')->count(),
            'failed' => $items->where('status', 'failed')->count(),
            'finished' => $items->whereIn('status', ['live', 'done'])->count(),
        ];

        return ['rows' => $rows, 'counts' => $counts, 'selected' => $selected, 'log' => array_values($log), 'agentErrors' => array_values(array_unique($errors))];
    }

    private function isMoving(MigrationItem $item): bool
    {
        return $item->status === 'queued' || $item->isActive();
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return ['queued' => __('Queued'), 'preparing' => __('Preparing'), 'copying' => __('Copying'), 'copied' => __('Copied'),
            'syncing' => __('Syncing'), 'live' => __('DNS switched'), 'done' => __('Finished'), 'failed' => __('Failed')];
    }
}
