<?php

namespace App\Http\Controllers;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BastContextService;
use App\Services\BastService;
use App\Services\M1CommandService;
use App\Services\PrivateEvidenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BastController extends Controller
{
    public function index(Request $request, WorkOrder $workOrder, M1CommandService $commands, BastContextService $context): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $wo = WorkOrder::query()->visibleTo($actor)->with('site.customer')->findOrFail($workOrder->id);
        $versions = $this->visibleQuery($actor)->whereHas('items.reportRevision', fn (Builder $query) => $query->where('work_order_id', $wo->id))
            ->with('document')->orderByDesc('id')->paginate(10)->through(function (BastVersion $version) use ($context, $actor): array {
                $context->visibleVersion($actor, $version->id);

                return ['id' => $version->id, 'number' => $version->document->document_number, 'version_no' => $version->version_no,
                    'status' => $version->status, 'url' => route('bast.show', $version)];
            });

        return Inertia::render('work-orders/acceptance/index', [
            'workOrder' => ['id' => $wo->id, 'number' => $wo->wo_number, 'title' => $wo->title],
            'versions' => $versions,
            'candidates' => $actor->hasRole('koordinator') ? $this->candidates($wo) : [],
            'create' => $actor->hasRole('koordinator') && filled($wo->acceptance_context_ref)
                ? $this->action('work-orders.acceptance.store', [$wo]) : null,
            'links' => ['reports' => route('work-orders.reports.index', $wo), 'refresh' => route('work-orders.acceptance.index', $wo)],
        ]);
    }

    public function show(Request $request, int $version, BastContextService $context, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $target = $context->visibleVersion($actor, $version);
        $coordinator = $actor->hasRole('koordinator');
        $head = (int) $target->document->versions()->max('version_no') === $target->version_no;
        $wo = $target->items->first()?->reportRevision->workOrder;
        $wo ??= WorkOrder::query()->visibleTo($actor)->where('acceptance_context_ref', $target->document->acceptance_context_ref)
            ->whereHas('site', fn (Builder $site) => $site->where('customer_id', $target->document->customer_id))->with('site')->first();
        $candidates = $coordinator && $wo ? $this->candidates($wo) : [];
        $target->load('items.reportRevision.workOrder.technicians:id,name,role,is_active,approval_status');
        $items = $target->items->sortBy('ordinal')->map(function (BastItem $item) use ($target, $coordinator): array {
            $report = $item->reportRevision;
            $wo = $report->workOrder;
            $snapshot = collect($target->scope_snapshot['items'] ?? [])->firstWhere('report_revision_id', $report->id);

            return ['report_id' => $report->id, 'scope_id' => $report->scope_item_id,
                'work_order_id' => $wo->id, 'work_order_version' => (int) $wo->lock_version,
                'work_order_number' => $snapshot['work_order_number'] ?? $wo->wo_number,
                'description' => $snapshot['description'] ?? $report->scopeItem->description,
                'acceptance_criteria' => $snapshot['acceptance_criteria'] ?? $report->scopeItem->acceptance_criteria,
                'revision_no' => $report->revision_no, 'site_id' => $wo->customer_site_id,
                'report_url' => route('work-orders.reports.show', [$wo, $report]),
                'authors' => $coordinator ? $wo->technicians->filter(fn (User $user): bool => $user->hasRole('teknisi') && $user->isActive() && $user->isApproved())->values()->map->only(['id', 'name'])->all() : []];
        })->values()->all();
        $versions = $this->visibleQuery($actor)->where('document_id', $target->document_id)->orderByDesc('version_no')->get()->map(fn (BastVersion $other): array => [
            'id' => $other->id, 'version_no' => $other->version_no, 'status' => $other->status, 'url' => route('bast.show', $other),
        ])->all();

        return Inertia::render('work-orders/acceptance/show', [
            'version' => [...$target->only(['id', 'version_no', 'status', 'lock_version', 'reason', 'signer_name', 'signer_capacity', 'authority_ref']),
                'number' => $target->document->document_number, 'context' => $target->document->acceptance_context_ref,
                'customer' => $target->document->customer->name, 'issued_at' => $target->issued_at?->toISOString(),
                'decided_at' => $target->decided_at?->toISOString(), 'recorded_at' => $target->recorded_at?->toISOString(),
                'recorder' => $target->recorded_by ? User::query()->find($target->recorded_by)?->name : null,
                'issued_url' => $target->issued_file_id ? route('bast.download', [$target, 'issued']) : null,
                'decision_url' => $target->decision_file_id ? route('bast.download', [$target, 'decision']) : null],
            'items' => $items, 'candidates' => $candidates, 'versions' => $versions,
            'actions' => [
                'update' => $coordinator && $head && $target->status === 'draft' ? $this->action('bast.update', [$target]) : null,
                'issue' => $coordinator && $head && $target->status === 'draft' && count($items) > 0 ? [...$this->action('bast.issue', [$target]), 'upload_key' => (string) Str::uuid()] : null,
                'decide' => $coordinator && $head && $target->status === 'pending' ? [...$this->action('bast.decide', [$target]), 'upload_key' => (string) Str::uuid()] : null,
                'cancel' => $coordinator && $head && in_array($target->status, ['draft', 'pending'], true) ? $this->action('bast.cancel', [$target]) : null,
                'revise' => $coordinator && $head && $target->status !== 'accepted' ? $this->action('bast.revise', [$target]) : null,
            ],
            'links' => ['refresh' => route('bast.show', $target), 'index' => $wo ? route('work-orders.acceptance.index', $wo) : route('work-orders.index')],
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder, BastService $service): RedirectResponse
    {
        $wo = WorkOrder::query()->visibleTo($request->user())->with('site')->findOrFail($workOrder->id);
        $version = $service->createDraft($request->user(), [...$request->only(['command_key', 'report_ids', 'work_order_versions']),
            'customer_id' => $wo->site->customer_id, 'acceptance_context_ref' => $wo->acceptance_context_ref]);

        return redirect()->route('bast.show', $version)->with('success', 'Draft BAST dibuat.');
    }

    public function update(Request $request, int $version, BastService $service): RedirectResponse
    {
        $service->updateDraft($request->user(), $version, $request->only(['command_key', 'expected_version', 'report_ids', 'work_order_versions']));

        return redirect()->route('bast.show', $version)->with('success', 'Scope draft BAST diperbarui. Gunakan berkas penerbitan baru.');
    }

    public function issue(Request $request, int $version, BastService $service): RedirectResponse
    {
        return $this->uploadedCommand($request, $version, $service, 'issue', 'issued');
    }

    public function decide(Request $request, int $version, BastService $service): RedirectResponse
    {
        return $this->uploadedCommand($request, $version, $service, 'decide', 'decision');
    }

    public function cancel(Request $request, int $version, BastService $service): RedirectResponse
    {
        $service->cancel($request->user(), $version, $request->only(['command_key', 'expected_version', 'reason']));

        return redirect()->route('bast.show', $version)->with('success', 'Versi BAST dibatalkan; evidence tetap dipertahankan.');
    }

    public function revise(Request $request, int $version, BastService $service): RedirectResponse
    {
        $next = $service->nextVersion($request->user(), $version, $request->only(['command_key', 'expected_version', 'report_ids', 'work_order_versions', 'reason']));

        return redirect()->route('bast.show', $next)->with('success', 'Versi draft pengganti dibuat.');
    }

    public function download(Request $request, int $version, string $purpose, BastContextService $context, PrivateEvidenceService $files): StreamedResponse
    {
        $target = $context->visibleVersion($request->user(), $version);
        abort_unless(in_array($purpose, ['issued', 'decision'], true), 404);
        $file = $purpose === 'issued' ? $target->issuedFile : $target->decisionFile;
        abort_unless($file && $file->state === 'ready', 404);
        $files->verify($file);

        return Storage::disk('evidence')->download($file->storage_key, $file->original_name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function uploadedCommand(Request $request, int $version, BastService $service, string $command, string $purpose): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file'], 'upload_key' => ['required', 'uuid']]);
        $file = $service->upload($request->user(), $version, $request->file('file'), [
            ...$request->only(['expected_version', 'sha256', 'scope_confirmed']), 'command_key' => $request->input('upload_key'), 'purpose' => $purpose,
        ]);
        $service->{$command}($request->user(), $version, [...$request->only(['command_key', 'expected_version', 'scope_confirmed', 'signer_name', 'signer_capacity',
            'authority_ref', 'authorized_site_ids', 'decision', 'decided_at', 'reason', 'category', 'rework_scope_authors']), 'evidence_file_id' => $file->id]);

        return redirect()->route('bast.show', $version)->with('success', $command === 'issue' ? 'Versi BAST diterbitkan dan menunggu keputusan customer.' : 'Keputusan customer dan bukti eksternal tercatat.');
    }

    private function visibleQuery(User $actor): Builder
    {
        $query = BastVersion::query()->whereDoesntHave('items.reportRevision', fn (Builder $reports) => $reports
            ->whereNotIn('work_order_id', WorkOrder::query()->visibleTo($actor)->select('id')));
        if ($actor->hasRole('teknisi')) {
            $query->whereHas('items');
        }

        return $query;
    }

    private function candidates(WorkOrder $wo): array
    {
        return ServiceReport::query()->whereNotNull('scope_item_id')->where('revision_no', '>', 0)->where('review_status', 'approved')
            ->whereHas('scopeItem', fn (Builder $query) => $query->where('disposition', 'active'))
            ->whereHas('workOrder', fn (Builder $query) => $query->where('acceptance_context_ref', $wo->acceptance_context_ref)
                ->whereNotIn('status', ['closed', 'cancelled', 'on_hold'])->whereHas('site', fn (Builder $site) => $site->where('customer_id', $wo->site->customer_id)))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('service_reports as newer')
                ->whereColumn('newer.scope_item_id', 'service_reports.scope_item_id')->whereColumn('newer.revision_no', '>', 'service_reports.revision_no'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('bast_items as bi')->join('bast_versions as bv', 'bv.id', '=', 'bi.version_id')
                ->join('service_reports as claimed', 'claimed.id', '=', 'bi.report_revision_id')->whereColumn('claimed.scope_item_id', 'service_reports.scope_item_id')->whereIn('bv.status', ['pending', 'accepted']))
            ->with(['scopeItem', 'workOrder.site'])->orderBy('work_order_id')->orderBy('scope_item_id')->get()->map(fn (ServiceReport $report): array => [
                'report_id' => $report->id, 'scope_id' => $report->scope_item_id, 'work_order_id' => $report->work_order_id,
                'work_order_version' => (int) $report->workOrder->lock_version, 'work_order_number' => $report->workOrder->wo_number,
                'revision_no' => $report->revision_no, 'description' => $report->scopeItem->description,
                'acceptance_criteria' => $report->scopeItem->acceptance_criteria, 'site_id' => $report->workOrder->customer_site_id,
                'site_name' => $report->workOrder->site->site_name,
            ])->all();
    }

    private function action(string $route, array $parameters): array
    {
        return ['url' => route($route, $parameters), 'command_key' => (string) Str::uuid()];
    }
}
