<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\StatisticsData;
use App\Support\StatsRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Server statistics: long-term graphs (CPU, load, memory, disk, network,
 * visitors, requests, data traffic), the same split per customer or website,
 * and which websites cause the most load. Graphs load their data from data().
 */
class StatisticsController extends Controller
{
    public const SORTS = ['cpu', 'memory', 'visitors', 'traffic'];

    public function index(Request $request, StatisticsData $stats): View
    {
        $range = StatsRange::fromRequest($request);
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'cpu';
        $latest = DB::table('metric_samples')->where('resolution', 'minute')->orderByDesc('taken_at')->first();

        return view('admin.statistics', [
            'range' => $range,
            'sort' => $sort,
            'latest' => $latest,
            'totals' => $stats->totals($range),
            'topWebsites' => $stats->websites($range, $sort),
            'topCustomers' => $stats->customers($range, $sort, 10),
            'customers' => Customer::orderByRaw('coalesce(company, name)')->get(['id', 'name', 'company']),
        ]);
    }

    public function data(Request $request, StatisticsData $stats): JsonResponse
    {
        $request->validate([
            'chart' => ['required', Rule::in([...array_keys(StatisticsData::SERVER), 'breakdown'])],
            'metric' => ['nullable', Rule::in(array_keys(StatisticsData::BREAKDOWN))],
            'by' => ['nullable', Rule::in(['customer', 'website'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.StatisticsData::MAX_SERIES],
            'customer' => ['nullable', 'integer'],
        ]);
        $range = StatsRange::fromRequest($request);

        $data = $request->query('chart') === 'breakdown'
            ? $stats->breakdown($request->query('metric', 'visitors'), $request->query('by', 'customer'), $range,
                (int) $request->query('limit', StatisticsData::MAX_SERIES), $request->integer('customer') ?: null)
            : $stats->server($request->query('chart'), $range);

        return response()->json($data + ['from' => $range->from->getTimestamp(), 'to' => $range->to->getTimestamp()]);
    }
}
