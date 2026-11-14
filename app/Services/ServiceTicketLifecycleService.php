<?php

namespace App\Services;

use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use Illuminate\Support\Facades\Validator;

class ServiceTicketLifecycleService
{
    public function __construct(private readonly M1CommandService $commands) {}

    public function change(User $actor, int $ticketId, string $action, array $payload): ServiceTicket
    {
        $this->commands->authorize($actor, ['koordinator']);
        abort_unless(in_array($action, ['resolve', 'wait_information', 'await_authorization', 'reject', 'cancel', 'duplicate'], true), 404);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_ticket_version' => ['required', 'integer', 'min:0'],
            'note' => ['required', 'string', 'max:10000'],
            'duplicate_of_id' => [$action === 'duplicate' ? 'required' : 'prohibited', 'integer', 'min:1'],
        ])->validate();
        $data['expected_ticket_version'] = (int) $data['expected_ticket_version'];
        if (isset($data['duplicate_of_id'])) {
            $data['duplicate_of_id'] = (int) $data['duplicate_of_id'];
        }
        $ticketIds = isset($data['duplicate_of_id']) ? BastContextService::ids([$ticketId, $data['duplicate_of_id']]) : [$ticketId];
        $history = $this->commands->run($actor, $data['command_key'], 'ticket.'.$action, ['ticket_id' => $ticketId, 'input' => $data], ['koordinator'],
            function () use ($ticketIds, $ticketId, $data): array {
                $tickets = ServiceTicket::query()->whereIn('id', $ticketIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $ticket = $tickets->get($ticketId);
                abort_unless($ticket, 404);
                $target = isset($data['duplicate_of_id']) ? $tickets->get($data['duplicate_of_id']) : null;
                $workOrders = WorkOrder::query()->where('ticket_id', $ticketId)->orderBy('id')->lockForUpdate()->get();
                $scopes = WorkOrderScopeItem::query()->whereIn('work_order_id', $workOrders->modelKeys())->orderBy('id')->lockForUpdate()->get();

                return compact('ticket', 'target', 'workOrders', 'scopes');
            }, function (array $context) use ($data, $action): array {
                ['ticket' => $ticket, 'target' => $target, 'workOrders' => $workOrders, 'scopes' => $scopes] = $context;
                if ($ticket->lock_version !== $data['expected_ticket_version']) {
                    $this->commands->conflict('expected_ticket_version', 'TICKET_VERSION_CONFLICT');
                }
                $allowed = match ($action) {
                    'resolve' => ['triaged', 'in_fulfillment'],
                    'wait_information', 'duplicate' => ['new', 'triaged'],
                    'await_authorization' => ['triaged'],
                    'reject' => ['new', 'triaged', 'awaiting_authorization'],
                    'cancel' => ['new', 'triaged', 'waiting_information', 'awaiting_authorization', 'approved', 'in_fulfillment'],
                };
                if (! in_array($ticket->status, $allowed, true)) {
                    $this->commands->conflict('ticket_id', 'TICKET_STATE_CONFLICT');
                }
                $reports = ServiceReport::query()->whereIn('work_order_id', $workOrders->modelKeys())->orderBy('id')->lockForUpdate()->get();
                if ($action === 'resolve') {
                    if ($ticket->status === 'triaged') {
                        if ($workOrders->isNotEmpty() || ! in_array($ticket->coverage_disposition, ['covered', 'authorized', 'no_charge'], true)
                            || ($ticket->coverage_disposition === 'authorized' && blank($ticket->authorization_ref))) {
                            $this->commands->conflict('ticket_id', 'REMOTE_RESOLUTION_NOT_ELIGIBLE');
                        }
                    } else {
                        if ($workOrders->isEmpty()) {
                            $this->commands->conflict('ticket_id', 'TICKET_FULFILLMENT_PROVENANCE_REQUIRED');
                        }
                        foreach ($workOrders as $wo) {
                            if (! in_array($wo->status, ['closed', 'cancelled'], true)) {
                                $this->commands->conflict('ticket_id', 'TICKET_WORK_ORDERS_STILL_OPEN');
                            }
                            $woScopes = $scopes->where('work_order_id', $wo->id);
                            $actionName = $wo->status === 'closed' ? 'work_order.close' : 'work_order.cancel';
                            $decision = OperationHistory::query()->where('subject_type', 'work_order')->where('subject_id', $wo->id)
                                ->where('action', $actionName)->orderByDesc('id')->lockForUpdate()->first();
                            if (! $decision) {
                                $this->commands->conflict('ticket_id', 'TICKET_FULFILLMENT_PROVENANCE_REQUIRED');
                            }
                            if ($wo->status === 'closed') {
                                $required = $woScopes->where('disposition', 'active')->where('is_required', true);
                                $proof = $decision->facts['closure_evidence'] ?? [];
                                if ($required->isEmpty() || ! $wo->closed_at || BastContextService::ids($required->modelKeys()) !== BastContextService::ids(array_column($proof, 'scope_item_id'))) {
                                    $this->commands->conflict('ticket_id', 'TICKET_OPERATIONAL_SCOPES_STILL_OPEN');
                                }
                                foreach ($proof as $evidence) {
                                    $head = $reports->where('scope_item_id', $evidence['scope_item_id'])->sortByDesc('revision_no')->first();
                                    if (! $head || $head->review_status !== 'approved' || $head->id !== $evidence['report_revision_id']) {
                                        $this->commands->conflict('ticket_id', 'TICKET_OPERATIONAL_SCOPES_STILL_OPEN');
                                    }
                                }
                            } elseif ($woScopes->contains('disposition', 'active')) {
                                $this->commands->conflict('ticket_id', 'TICKET_OPERATIONAL_SCOPES_STILL_OPEN');
                            }
                        }
                    }
                    $ticket->forceFill(['resolution_note' => $data['note'], 'resolved_at' => now()]);
                } elseif ($action === 'duplicate') {
                    $hasDependants = ServiceTicket::query()->where('duplicate_of_id', $ticket->id)->lockForUpdate()->first() !== null;
                    if (! $target || $target->id === $ticket->id || $target->status === 'duplicate' || $workOrders->isNotEmpty() || $hasDependants) {
                        $this->commands->conflict('duplicate_of_id', 'CANONICAL_TICKET_REQUIRED');
                    }
                    $ticket->duplicate_of_id = $target->id;
                } elseif ($action === 'cancel') {
                    foreach ($workOrders as $wo) {
                        if ($wo->status !== 'cancelled' || $scopes->where('work_order_id', $wo->id)->contains('disposition', 'active')
                            || ! OperationHistory::query()->where('subject_type', 'work_order')->where('subject_id', $wo->id)
                                ->where('action', 'work_order.cancel')->lockForUpdate()->first()) {
                            $this->commands->conflict('ticket_id', 'TICKET_CANCELLATION_REQUIRES_CHILD_DISPOSITION');
                        }
                    }
                } elseif ($workOrders->isNotEmpty()) {
                    $this->commands->conflict('ticket_id', 'TICKET_WORK_ORDERS_STILL_OPEN');
                }
                $from = $ticket->status;
                $ticket->status = match ($action) {
                    'resolve' => 'resolved', 'wait_information' => 'waiting_information', 'await_authorization' => 'awaiting_authorization',
                    'reject' => 'rejected', 'cancel' => 'cancelled', 'duplicate' => 'duplicate',
                };
                $ticket->lock_version++;
                $ticket->save();

                return ['subject_type' => 'service_ticket', 'subject_id' => $ticket->id, 'from_state' => $from, 'to_state' => $ticket->status, 'reason' => $data['note'],
                    'facts' => ['ticket_id' => $ticket->id, 'lock_version' => $ticket->lock_version, 'work_order_ids' => $workOrders->modelKeys(),
                        'duplicate_of_id' => $ticket->duplicate_of_id, 'resolution_kind' => $action === 'resolve' ? ($workOrders->isEmpty() ? 'remote' : 'all_work_orders_final') : null]];
            });

        return ServiceTicket::query()->findOrFail($history->subject_id);
    }
}
