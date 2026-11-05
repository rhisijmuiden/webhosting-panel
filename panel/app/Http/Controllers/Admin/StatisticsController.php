<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Metrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\View\View;

class StatisticsController extends Controller
{
    public function __invoke(Request $request, Metrics $metrics): View
    {
        $range = array_key_exists($request->query('range'), Metrics::RANGES) ? $request->query('range') : '24h';
        $series = $metrics->serverSeries($range);
        $timeFormat = $range === '24h' ? 'HH:mm' : ($range === '1y' ? 'D MMM YYYY' : 'D MMM HH:mm');
        $points = $series->map(fn ($p) => $p + ['label' => Carbon::createFromTimestamp($p['t'])->setTimezone(config('app.timezone'))->isoFormat($timeFormat)]);

        $bps = fn ($v) => Number::fileSize($v, precision: 1).'/s';

        return view('admin.statistics', [
            'range' => $range,
            'points' => $points,
            'latest' => $points->last(),
            'peakCpu' => $points->max('cpu'),
            'avgCpu' => round($points->avg('cpu') ?? 0, 1),
            'charts' => [
                'cpu' => ['title' => __('CPU'), 'max' => 100, 'series' => [['key' => 'cpu', 'label' => __('CPU'), 'format' => fn ($v) => $v.'%']]],
                'memory' => ['title' => __('Memory'), 'max' => 100, 'series' => [['key' => 'memory', 'label' => __('Memory in use'), 'format' => fn ($v) => $v.'%']]],
                'network' => ['title' => __('Network'), 'max' => null, 'series' => [
                    ['key' => 'tx', 'label' => __('Out'), 'format' => $bps],
                    ['key' => 'rx', 'label' => __('In'), 'format' => $bps],
                ], 'axis' => $bps],
                'disk' => ['title' => __('Disk space'), 'max' => 100, 'series' => [['key' => 'disk', 'label' => __('Disk in use'), 'format' => fn ($v) => $v.'%']]],
            ],
            'topWebsites' => $metrics->topWebsites($range),
            'topCustomers' => $metrics->topCustomers($range),
        ]);
    }
}
