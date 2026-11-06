<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Http;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Rhis\Monitoring\Components;
use Rhis\Monitoring\Models\Notice;
use Rhis\Monitoring\Options;
use Rhis\Monitoring\Severity;

class StatusPageController extends Controller
{
    public function show(Options $options): View
    {
        abort_unless($options->get('status_page'), 404);
        $components = Components::overview(90);
        $notices = Notice::visible()->latest('id')->get();
        $maintenance = null;
        if (class_exists(\Rhis\ServerAdmin\Options::class)) {
            try {
                $sa = app(\Rhis\ServerAdmin\Options::class);
                $maintenance = $sa->get('window_enabled') ? ['next' => $sa->nextWindow(), 'start' => $sa->get('window_start'), 'end' => $sa->get('window_end')] : null;
            } catch (\Throwable) {
                $maintenance = null;
            }
        }

        return view('monitoring::public.status', [
            'title' => __($options->get('status_title')),
            'components' => $components,
            'overall' => Severity::worst(array_column($components, 'status')),
            'notices' => $notices,
            'incidents' => Components::incidents(14),
            'maintenance' => $maintenance,
            'updated' => $options->state('last_result')['at'] ?? null,
        ]);
    }
}
