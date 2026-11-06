<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\WordPress\Models\WordPressInstall;

/** Which customers are most at risk: points per open finding, plus outdated parts without a known hole. */
final class Risk
{
    public static function level(float $score): string
    {
        return match (true) {
            $score >= 80 => 'critical',
            $score >= 40 => 'high',
            $score >= 10 => 'medium',
            $score > 0 => 'low',
            default => 'none',
        };
    }

    /** @return Collection<int, array{customer: Customer, score: int, level: string, counts: array, malware: int, sites: int, outdated: int, top: ?Finding}> */
    public static function ranking(int $limit = 10): Collection
    {
        $findings = Finding::open()->with('website')->get()->groupBy(fn (Finding $f) => $f->website->customer_id);
        $outdated = WordPressInstall::with('website')->get()->groupBy(fn ($i) => $i->website->customer_id)
            ->map(fn ($list) => $list->sum(fn ($i) => $i->outdatedCount()));
        $ids = $findings->keys()->merge($outdated->filter()->keys())->unique()->filter();
        $customers = Customer::whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(function ($id) use ($findings, $outdated, $customers) {
            $list = $findings->get($id, collect());
            $out = (int) $outdated->get($id, 0);
            $score = (int) round(min(100, $list->sum(fn (Finding $f) => $f->weight()) + $out));
            $counts = array_fill_keys(Finding::SEVERITIES, 0);
            foreach ($list->where('kind', 'vulnerability') as $f) {
                $counts[$f->severity]++;
            }

            return [
                'customer' => $customers->get($id), 'score' => $score, 'level' => self::level($score),
                'counts' => $counts, 'malware' => $list->where('kind', 'malware')->count(),
                'sites' => $list->pluck('website_id')->unique()->count(), 'outdated' => $out,
                'top' => $list->sortBy(fn (Finding $f) => [$f->isMalware() ? 0 : 1, Finding::rank($f->severity), -($f->cvss_score ?? 0)])->first(),
            ];
        })->filter(fn ($r) => $r['customer'] && $r['score'] > 0)->sortByDesc('score')->take($limit)->values();
    }

    /** Totals over all open findings. */
    public static function totals(?int $customerId = null): array
    {
        $q = fn () => Finding::open()->when($customerId, fn ($q) => $q->forCustomer($customerId));
        $by = $q()->where('kind', 'vulnerability')->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity');

        return [
            'critical' => (int) ($by['critical'] ?? 0), 'high' => (int) ($by['high'] ?? 0),
            'medium' => (int) ($by['medium'] ?? 0), 'low' => (int) (($by['low'] ?? 0) + ($by['unknown'] ?? 0)),
            'malware' => $q()->where('kind', 'malware')->count(),
            'sites' => $q()->distinct()->count('website_id'),
            'no_fix' => $q()->where('kind', 'vulnerability')->whereNull('fixed_version')->count(),
            'total' => $q()->count(),
            'resolved_30d' => Finding::where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(30))
                ->when($customerId, fn ($q) => $q->forCustomer($customerId))->count(),
        ];
    }
}
