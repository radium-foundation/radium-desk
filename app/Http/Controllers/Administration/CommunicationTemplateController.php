<?php

namespace App\Http\Controllers\Administration;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreCommunicationTemplateRequest;
use App\Http\Requests\Administration\UpdateCommunicationTemplateRequest;
use App\Models\CommunicationTemplate;
use App\Services\CommunicationTemplates\CommunicationTemplateBladeImporter;
use App\Services\CommunicationTemplates\CommunicationTemplateComparisonService;
use App\Services\CommunicationTemplates\CommunicationTemplatePreviewService;
use App\Services\CommunicationTemplates\CommunicationTemplateStoreService;
use App\Services\CommunicationTemplates\CommunicationTemplateTestSendService;
use App\Services\CommunicationTemplates\CommunicationTemplateVariableCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommunicationTemplateController extends Controller
{
    public function __construct(
        private readonly CommunicationTemplateStoreService $store,
        private readonly CommunicationTemplatePreviewService $preview,
        private readonly CommunicationTemplateVariableCatalog $variables,
        private readonly CommunicationTemplateBladeImporter $importer,
        private readonly CommunicationTemplateComparisonService $comparison,
        private readonly CommunicationTemplateTestSendService $testSendService,
    ) {
        $this->authorizeResource(CommunicationTemplate::class, 'communication_template');
    }

    public function index(Request $request): View
    {
        $query = CommunicationTemplate::query()
            ->with(['updater'])
            ->orderBy('name');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('channel')) {
            $channel = $request->string('channel')->toString();
            $query->whereJsonContains('channels', $channel);
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q')->toString().'%';
            $query->where(function ($builder) use ($q): void {
                $builder->where('name', 'like', $q)->orWhere('key', 'like', $q);
            });
        }

        return view('admin.communication-templates.index', [
            'templates' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['q', 'category', 'status', 'channel']),
            'categories' => CommunicationTemplateCategory::cases(),
            'statuses' => CommunicationTemplateStatus::cases(),
            'channels' => CommunicationTemplateChannel::cases(),
            'inventory' => $this->importer->inventory(),
            'canManage' => $request->user()?->can('manage', CommunicationTemplate::class) ?? false,
        ]);
    }

    public function create(): View
    {
        return view('admin.communication-templates.create', $this->formShared());
    }

    public function store(StoreCommunicationTemplateRequest $request): RedirectResponse
    {
        $template = $this->store->create($request->validated(), $request->user());

        return redirect()
            ->route('admin.communication-templates.show', $template)
            ->with('status', 'Template created as version 1.');
    }

    public function show(CommunicationTemplate $communicationTemplate): View
    {
        $communicationTemplate->load([
            'versions.creator',
            'updater',
            'creator',
            'usages' => fn ($q) => $q->with('user')->latest('used_at')->limit(20),
        ]);
        $preview = $this->preview->previewTemplate($communicationTemplate, [], request()->user());
        $comparison = $this->comparison->compare($communicationTemplate, request()->user());

        return view('admin.communication-templates.show', [
            'template' => $communicationTemplate,
            'preview' => $preview,
            'comparison' => $comparison,
            'canManage' => request()->user()?->can('update', $communicationTemplate) ?? false,
            'variables' => $this->variables->all(),
        ]);
    }

    public function edit(CommunicationTemplate $communicationTemplate): View
    {
        $version = $communicationTemplate->currentVersionRecord();

        return view('admin.communication-templates.edit', array_merge($this->formShared(), [
            'template' => $communicationTemplate,
            'version' => $version,
        ]));
    }

    public function update(
        UpdateCommunicationTemplateRequest $request,
        CommunicationTemplate $communicationTemplate,
    ): RedirectResponse {
        $this->store->revise($communicationTemplate, $request->validated(), $request->user());

        return redirect()
            ->route('admin.communication-templates.show', $communicationTemplate)
            ->with('status', 'Draft version saved. Approve to publish to runtime (approved snapshot stays live until then).');
    }

    public function approve(CommunicationTemplate $communicationTemplate): RedirectResponse
    {
        $this->authorize('update', $communicationTemplate);
        $this->store->approve($communicationTemplate, request()->user());

        return back()->with('status', 'Template approved. Runtime now uses the approved store version (Blade remains fallback).');
    }

    public function deprecate(CommunicationTemplate $communicationTemplate): RedirectResponse
    {
        $this->authorize('update', $communicationTemplate);
        $this->store->deprecate($communicationTemplate, request()->user());

        return back()->with('status', 'Template deprecated. Runtime falls back to Blade.');
    }

    public function rollback(Request $request, CommunicationTemplate $communicationTemplate): RedirectResponse
    {
        $this->authorize('update', $communicationTemplate);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->store->rollback(
            $communicationTemplate,
            (int) $validated['version'],
            $request->user(),
            $validated['change_reason'] ?? null,
        );

        return redirect()
            ->route('admin.communication-templates.show', $communicationTemplate)
            ->with('status', 'Rolled back to version '.$validated['version'].' as a new draft. Approve to publish.');
    }

    public function compare(CommunicationTemplate $communicationTemplate): View
    {
        $this->authorize('view', $communicationTemplate);

        return view('admin.communication-templates.compare', [
            'template' => $communicationTemplate,
            'comparison' => $this->comparison->compare($communicationTemplate, request()->user()),
            'canManage' => request()->user()?->can('update', $communicationTemplate) ?? false,
        ]);
    }

    public function testSend(Request $request, CommunicationTemplate $communicationTemplate): RedirectResponse
    {
        $this->authorize('update', $communicationTemplate);
        $validated = $request->validate([
            'recipient_email' => ['required', 'email', 'max:255'],
            'sample_order_id' => ['nullable', 'string', 'max:64'],
        ]);

        $order = null;
        if (! empty($validated['sample_order_id'])) {
            $order = \App\Models\Order::query()
                ->where('order_id', $validated['sample_order_id'])
                ->first();
        }

        $result = $this->testSendService->send(
            $communicationTemplate,
            $request->user(),
            $validated['recipient_email'],
            $order,
        );

        return back()->with(
            $result['success'] ? 'status' : 'error',
            $result['message'].' (runtime: '.$result['runtime_source'].($result['used_fallback'] ? ', fallback' : '').')',
        );
    }

    public function preview(Request $request, CommunicationTemplate $communicationTemplate): JsonResponse
    {
        $this->authorize('view', $communicationTemplate);

        $version = $communicationTemplate->currentVersionRecord();
        if ($version === null) {
            return response()->json(['html' => '', 'subject' => null, 'text' => ''], 422);
        }

        $payload = [
            'subject' => $request->input('subject', $version->subject),
            'greeting_style' => $request->input('greeting_style', $version->greeting_style?->value),
            'body_html' => $request->input('body_html', $version->body_html),
            'signature_mode' => $request->input('signature_mode', $version->signature_mode?->value),
        ];

        $draftVersion = $version->replicate();
        $draftVersion->subject = $payload['subject'];
        $draftVersion->greeting_style = CommunicationTemplateGreetingStyle::tryFrom((string) $payload['greeting_style'])
            ?? $version->greeting_style;
        $draftVersion->body_html = (string) $payload['body_html'];
        $draftVersion->signature_mode = CommunicationTemplateSignatureMode::tryFrom((string) $payload['signature_mode'])
            ?? $version->signature_mode;

        return response()->json(
            $this->preview->previewVersion($draftVersion, [], $request->user()),
        );
    }

    public function importBlade(Request $request): RedirectResponse
    {
        $this->authorize('create', CommunicationTemplate::class);

        $result = $this->importer->importAll($request->user(), approve: true);

        return redirect()
            ->route('admin.communication-templates.index')
            ->with('status', "Blade import complete. Imported {$result['imported']}, skipped {$result['skipped']}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formShared(): array
    {
        return [
            'categories' => CommunicationTemplateCategory::cases(),
            'channels' => CommunicationTemplateChannel::cases(),
            'greetings' => CommunicationTemplateGreetingStyle::cases(),
            'signatures' => CommunicationTemplateSignatureMode::cases(),
            'variables' => $this->variables->all(),
        ];
    }
}
