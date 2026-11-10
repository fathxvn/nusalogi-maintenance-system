<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CancelVisitBookingService;
use App\Services\M1CommandService;
use App\Services\VisitSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkVisitController extends Controller
{
    private const DRAFT_STATES = ['pending', 'assigned', 'in_progress', 'on_hold', 'rework'];

    private const CONFIRM_STATES = ['assigned', 'in_progress', 'rework'];

    private const CANCEL_STATES = ['assigned', 'in_progress', 'on_hold', 'verification', 'pending_review', 'awaiting_acceptance', 'rework'];

    public function index(Request $request, WorkOrder $workOrder, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $workOrder = $this->visible($actor, $workOrder);
        $baseline = $this->hasBaseline($workOrder);
        $coordinator = $actor->hasRole('koordinator');
        $canConfirm = $coordinator && $baseline && in_array($workOrder->status, self::CONFIRM_STATES, true);
        $canCancel = $coordinator && in_array($workOrder->status, self::CANCEL_STATES, true);

        $visits = $workOrder->visits()->with([
            'bookings.technician:id,name',
            'previousVisit:id,visit_no',
        ])->orderByDesc('visit_no')->paginate(10)->through(function (WorkVisit $visit) use ($workOrder, $canConfirm, $canCancel): array {
            return [
                'id' => $visit->id,
                'visit_no' => $visit->visit_no,
                'starts_at' => $this->utc($visit->getRawOriginal('starts_at')),
                'ends_at' => $this->utc($visit->getRawOriginal('ends_at')),
                'zone_name' => $visit->zone_name,
                'status' => $visit->status,
                'reason' => $visit->reason,
                'previous_visit_no' => $visit->previousVisit?->visit_no,
                'lock_version' => $visit->lock_version,
                'confirm_url' => $canConfirm && $visit->status === 'draft' && $visit->bookings->isEmpty()
                    ? route('work-orders.visits.confirm', [$workOrder, $visit]) : null,
                'command_key' => $canConfirm ? (string) Str::uuid() : null,
                'bookings' => $visit->bookings->sortBy('id')->values()->map(function (VisitBooking $booking) use ($workOrder, $visit, $canCancel): array {
                    $cancellable = $canCancel && in_array($visit->status, ['confirmed', 'in_progress'], true)
                        && $booking->status === 'confirmed' && $booking->actual_start_at === null && $booking->actual_end_at === null;

                    return [
                        'id' => $booking->id,
                        'technician_name' => $booking->technician?->name ?? 'Teknisi tidak tersedia',
                        'status' => $booking->status,
                        'ended_reason' => $booking->ended_reason,
                        'cancel_url' => $cancellable ? route('work-orders.visits.bookings.cancel', [$workOrder, $visit, $booking]) : null,
                        'command_key' => $cancellable ? (string) Str::uuid() : null,
                    ];
                })->all(),
            ];
        });

        return Inertia::render('work-orders/visits/index', [
            'workOrder' => $this->summary($workOrder),
            'members' => $workOrder->technicians->map->only(['id', 'name'])->all(),
            'technicians' => $coordinator ? $workOrder->technicians()
                ->where('role', 'teknisi')->where('is_active', true)->where('approval_status', 'approved')
                ->orderBy('name')->orderBy('users.id')->get(['users.id', 'users.name'])
                ->map->only(['id', 'name'])->all() : [],
            'visits' => $visits,
            'unavailableReason' => ! $baseline ? 'WO ini belum memiliki origin, acceptance context, dan scope wajib M1. Jadwal baru belum dapat dibuat.' : null,
            'links' => [
                'index' => route('work-orders.index'),
                'refresh' => route('work-orders.visits.index', ['workOrder' => $workOrder, 'page' => $visits->currentPage()]),
                'create' => $coordinator && $baseline && in_array($workOrder->status, self::DRAFT_STATES, true)
                    ? route('work-orders.visits.create', $workOrder) : null,
            ],
        ]);
    }

    public function create(Request $request, WorkOrder $workOrder, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), ['koordinator']);
        $workOrder = $this->visible($actor, $workOrder);
        abort_unless($this->hasBaseline($workOrder) && in_array($workOrder->status, self::DRAFT_STATES, true), 404);

        return Inertia::render('work-orders/visits/create', [
            'workOrder' => $this->summary($workOrder),
            'commandKey' => (string) Str::uuid(),
            'links' => [
                'index' => route('work-orders.visits.index', $workOrder),
                'store' => route('work-orders.visits.store', $workOrder),
            ],
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder, M1CommandService $commands, VisitSchedulingService $service): RedirectResponse
    {
        $actor = $commands->authorize($request->user(), ['koordinator']);
        $workOrder = $this->visible($actor, $workOrder);
        // Keep this outside an enclosing transaction: the service owns locks, retries and idempotency.
        $service->createDraft($actor, $workOrder->id, $request->only(['command_key', 'starts_at', 'ends_at', 'zone_name', 'reason']));

        return redirect()->route('work-orders.visits.index', $workOrder)->with('success', 'Rencana kunjungan tersimpan. Slot teknisi belum dipesan.');
    }

    public function confirm(Request $request, WorkOrder $workOrder, int $visit, M1CommandService $commands, VisitSchedulingService $service): RedirectResponse
    {
        $actor = $commands->authorize($request->user(), ['koordinator']);
        $workOrder = $this->visible($actor, $workOrder);
        $target = $workOrder->visits()->findOrFail($visit);
        $service->confirm($actor, $target->id, $request->only(['command_key', 'expected_visit_version', 'technician_ids']));

        return redirect()->route('work-orders.visits.index', $workOrder)->with('success', 'Peserta kunjungan berhasil dikonfirmasi.');
    }

    public function cancel(Request $request, WorkOrder $workOrder, int $visit, int $booking, M1CommandService $commands, CancelVisitBookingService $service): RedirectResponse
    {
        $actor = $commands->authorize($request->user(), ['koordinator']);
        $workOrder = $this->visible($actor, $workOrder);
        $target = $workOrder->visits()->findOrFail($visit)->bookings()->findOrFail($booking);
        $service->cancel($actor, $target->id, $request->only(['command_key', 'expected_visit_version', 'expected_booking_status', 'reason']));

        return redirect()->route('work-orders.visits.index', $workOrder)->with('success', 'Booking peserta dibatalkan. Keanggotaan Work Order tetap.');
    }

    private function visible(User $actor, WorkOrder $workOrder): WorkOrder
    {
        return WorkOrder::query()->visibleTo($actor)->with(['site.customer', 'technicians:id,name'])
            ->findOrFail($workOrder->id);
    }

    private function hasBaseline(WorkOrder $workOrder): bool
    {
        return in_array($workOrder->origin_kind, ['ticket', 'manual'], true) && filled($workOrder->acceptance_context_ref)
            && $workOrder->scopeItems()->where('disposition', 'active')->where('is_required', true)->exists();
    }

    /** @return array<string, mixed> */
    private function summary(WorkOrder $workOrder): array
    {
        return [
            'id' => $workOrder->id,
            'number' => $workOrder->wo_number,
            'title' => $workOrder->title,
            'status' => $workOrder->status,
            'customer' => $workOrder->site?->customer?->name,
            'site' => $workOrder->site?->site_name,
        ];
    }

    private function utc(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
