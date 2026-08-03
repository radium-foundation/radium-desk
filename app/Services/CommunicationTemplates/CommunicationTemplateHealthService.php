<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateUsage;
use Illuminate\Support\Facades\DB;

class CommunicationTemplateHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $templates = CommunicationTemplate::query()->orderBy('name')->get();
        $total = $templates->count();
        $approved = $templates->where('status', CommunicationTemplateStatus::Approved)->count();
        $storeRuntime = $templates->filter(fn (CommunicationTemplate $t): bool => ($t->last_runtime_source ?: $t->runtime_source) === 'store')->count();
        $bladeRuntime = $templates->filter(fn (CommunicationTemplate $t): bool => ($t->last_runtime_source ?: $t->runtime_source) !== 'store')->count();
        $fallbackCount = (int) $templates->sum('fallback_count');
        $errorCount = $templates->filter(fn (CommunicationTemplate $t): bool => filled($t->last_error))->count();
        $withApprovedVersion = $templates->filter(fn (CommunicationTemplate $t): bool => (int) ($t->approved_version ?? 0) > 0)->count();
        $linked = $templates->filter(fn (CommunicationTemplate $t): bool => filled($t->notification_type))->count();

        $recentUsages = CommunicationTemplateUsage::query()
            ->with(['template', 'user'])
            ->latest('used_at')
            ->limit(20)
            ->get();

        $analytics = [
            'most_used' => $templates->sortByDesc('usage_count')->take(5)->values(),
            'least_used' => $templates->sortBy('usage_count')->take(5)->values(),
            'avg_edit_percent' => round((float) CommunicationTemplateUsage::query()->whereNotNull('edit_percent')->avg('edit_percent'), 1),
            'avg_send_duration_ms' => (int) round((float) CommunicationTemplateUsage::query()->whereNotNull('send_duration_ms')->avg('send_duration_ms')),
        ];

        $rows = $templates->map(function (CommunicationTemplate $template): array {
            return [
                'id' => $template->id,
                'name' => $template->name,
                'status' => $template->status->label(),
                'runtime' => $template->runtimeLabel(),
                'fallback' => $template->last_runtime_source === 'blade' && (int) $template->fallback_count > 0 ? 'Blade' : '—',
                'health' => strtoupper($template->runtimeHealth()),
                'fallback_count' => (int) $template->fallback_count,
                'usage_count' => (int) $template->usage_count,
                'last_send_at' => $template->last_send_at?->toDateTimeString(),
                'last_modified' => $template->updated_at?->toDateTimeString(),
                'approved_version' => $template->approved_version,
                'current_version' => $template->current_version,
                'notification_type' => $template->notification_type,
            ];
        })->all();

        return [
            'totals' => [
                'templates' => $total,
                'approved' => $approved,
                'blade_runtime' => $bladeRuntime,
                'store_runtime' => $storeRuntime,
                'fallback_count' => $fallbackCount,
                'template_errors' => $errorCount,
                'migration_progress' => $linked > 0 ? round(($withApprovedVersion / $linked) * 100, 1) : 0.0,
                'linked' => $linked,
                'with_approved_version' => $withApprovedVersion,
            ],
            'rows' => $rows,
            'recent_usages' => $recentUsages,
            'analytics' => $analytics,
            'channel_breakdown' => CommunicationTemplateUsage::query()
                ->select('channel', DB::raw('count(*) as total'))
                ->groupBy('channel')
                ->pluck('total', 'channel')
                ->all(),
        ];
    }
}
