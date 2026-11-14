<?php

namespace App\Services;

use App\Models\BastDocument;
use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Models\WorkVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BastContextService
{
    public function __construct(private readonly M1CommandService $commands) {}

    /** @param list<int> $reportIds @param list<int> $versionIds @param list<int> $authors @return array<string, list<int>> */
    public function discover(array $reportIds, array $versionIds = [], array $authors = [], array $extraWorkOrderIds = []): array
    {
        $reportIds = self::ids([...$reportIds, ...BastItem::query()->whereIn('version_id', $versionIds)->pluck('report_revision_id')->all()]);
        $workOrderIds = self::ids([...$extraWorkOrderIds, ...ServiceReport::query()->whereIn('id', $reportIds)->pluck('work_order_id')->all()]);
        $siteIds = self::ids(WorkOrder::query()->whereIn('id', $workOrderIds)->pluck('customer_site_id')->all());
        $customerIds = self::ids(CustomerSite::query()->whereIn('id', $siteIds)->pluck('customer_id')->all());
        $documentIds = self::ids(BastVersion::query()->whereIn('id', $versionIds)->pluck('document_id')->all());
        $resources = self::ids([...$authors, ...VisitBooking::query()->join('work_visits', 'work_visits.id', '=', 'visit_bookings.visit_id')
            ->whereIn('work_visits.work_order_id', $workOrderIds)->pluck('visit_bookings.technician_id')->all()]);

        return compact('reportIds', 'workOrderIds', 'siteIds', 'customerIds', 'documentIds', 'resources');
    }

    /** @param array<string, list<int>> $discovery @return array<string, Collection> */
    public function lockRoots(User $actor, array $discovery): array
    {
        $customers = Customer::query()->whereIn('id', $discovery['customerIds'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sites = CustomerSite::query()->whereIn('id', $discovery['siteIds'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $workOrders = WorkOrder::query()->whereIn('id', $discovery['workOrderIds'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($workOrders->count() !== count($discovery['workOrderIds']) || $workOrders->contains(fn (WorkOrder $wo): bool => ! $sites->has($wo->customer_site_id))) {
            $this->commands->conflict('version_id', 'BAST_PARENT_SET_CHANGED');
        }
        $scopes = WorkOrderScopeItem::query()->whereIn('work_order_id', $workOrders->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->commands->authorize($actor, ['koordinator'], true);
        $resources = User::query()->whereIn('id', $discovery['resources'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $memberships = DB::table('work_order_technicians')->whereIn('work_order_id', $workOrders->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $visits = WorkVisit::query()->whereIn('work_order_id', $workOrders->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $bookings = VisitBooking::query()->whereIn('visit_id', $visits->modelKeys())->orderBy('id')->lockForUpdate()->get();
        if (array_diff(self::ids($bookings->pluck('technician_id')->all()), $discovery['resources']) !== []) {
            $this->commands->conflict('version_id', 'BAST_PARENT_SET_CHANGED');
        }

        return compact('customers', 'sites', 'workOrders', 'scopes', 'bookings', 'resources', 'memberships', 'visits');
    }

    /** @param array<string, Collection> $context @return Collection<int, ServiceReport> */
    public function lockReports(array $context): Collection
    {
        return ServiceReport::query()->whereIn('work_order_id', $context['workOrders']->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param list<int> $documentIds @return array{documents: Collection, versions: Collection, items: Collection} */
    public function lockPackages(array $documentIds): array
    {
        $documents = BastDocument::query()->whereIn('id', $documentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $versions = BastVersion::query()->whereIn('document_id', $documentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = BastItem::query()->whereIn('version_id', $versions->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return compact('documents', 'versions', 'items');
    }

    /** @param list<int> $scopeIds @return \Illuminate\Support\Collection<int, object> */
    public function claims(array $scopeIds): \Illuminate\Support\Collection
    {
        return DB::table('bast_items as bi')->join('bast_versions as bv', 'bv.id', '=', 'bi.version_id')
            ->join('service_reports as sr', 'sr.id', '=', 'bi.report_revision_id')->whereIn('sr.scope_item_id', $scopeIds)
            ->whereIn('bv.status', ['pending', 'accepted'])->orderBy('bi.id')->lockForUpdate()
            ->get(['bi.id', 'bi.version_id', 'bi.report_revision_id', 'bv.status', 'bv.document_id', 'sr.scope_item_id']);
    }

    /** @param array<string, Collection> $context @param Collection<int, ServiceReport> $reports @param list<int> $reportIds @return list<array<string, mixed>> */
    public function eligible(array $context, Collection $reports, array $reportIds, int $customerId, string $reference): array
    {
        $items = [];
        $seen = [];
        foreach ($reportIds as $id) {
            $report = $reports->get($id);
            $scope = $report ? $context['scopes']->get($report->scope_item_id) : null;
            $wo = $report ? $context['workOrders']->get($report->work_order_id) : null;
            $site = $wo ? $context['sites']->get($wo->customer_site_id) : null;
            $head = $scope ? $reports->where('scope_item_id', $scope->id)->sortByDesc('revision_no')->first() : null;
            if (! $report || ! $scope || ! $wo || ! $site || (int) $scope->work_order_id !== (int) $wo->id
                || $scope->disposition !== 'active' || $report->revision_no < 1 || $report->review_status !== 'approved'
                || $report->submitted_at === null || $report->reviewed_at === null || ! $report->reviewed_by
                || ! $head?->is($report) || isset($seen[$scope->id])) {
                $this->commands->conflict('report_ids', 'BAST_APPROVED_HEAD_REQUIRED');
            }
            if ((int) $site->customer_id !== $customerId || $wo->acceptance_context_ref !== $reference || blank($reference)
                || ! in_array($wo->origin_kind, ['ticket', 'manual'], true) || in_array($wo->status, ['closed', 'cancelled', 'on_hold'], true)) {
                $this->commands->conflict('report_ids', 'BAST_CONTEXT_MISMATCH');
            }
            $seen[$scope->id] = true;
            $items[] = ['report_revision_id' => $report->id, 'revision_no' => $report->revision_no,
                'scope_item_id' => $scope->id, 'scope_no' => $scope->scope_no, 'description' => $scope->description,
                'acceptance_criteria' => $scope->acceptance_criteria, 'work_order_id' => $wo->id, 'work_order_number' => $wo->wo_number,
                'site_id' => $site->id, 'site_name' => $site->site_name, 'customer_id' => $customerId,
                'customer_name' => $context['customers']->get($customerId)?->name, 'acceptance_context_ref' => $reference];
        }

        return $items;
    }

    public function visibleVersion(User $actor, int $versionId): BastVersion
    {
        $this->commands->authorize($actor, User::ROLES);
        $version = BastVersion::query()->with(['document', 'items.reportRevision.scopeItem', 'items.reportRevision.workOrder.site'])->findOrFail($versionId);
        $woIds = self::ids($version->items->map(fn (BastItem $item): ?int => $item->reportRevision?->work_order_id)->filter()->all());
        abort_if(($woIds === [] && $actor->hasRole('teknisi')) || WorkOrder::query()->visibleTo($actor)->whereKey($woIds)->count() !== count($woIds), 404);
        foreach ($version->items as $item) {
            $report = $item->reportRevision;
            $scope = $report?->scopeItem;
            $wo = $report?->workOrder;
            abort_unless($scope && $wo && (int) $scope->work_order_id === (int) $wo->id
                && (int) $wo->site->customer_id === (int) $version->document->customer_id
                && $wo->acceptance_context_ref === $version->document->acceptance_context_ref, 404);
        }
        if ($version->scope_snapshot !== null) {
            abort_unless(self::ids(array_column($version->scope_snapshot['items'] ?? [], 'report_revision_id'))
                === self::ids($version->items->pluck('report_revision_id')->all()), 404);
        }

        return $version;
    }

    /** @param list<int|string> $ids @return list<int> */
    public static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
