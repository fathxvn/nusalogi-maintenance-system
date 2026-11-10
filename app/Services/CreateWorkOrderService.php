<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CustomerSite;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateWorkOrderService
{
    private const SERVICE_TYPES = ['install', 'maintenance'];

    private const PRIORITIES = ['low', 'medium', 'high'];

    public function __construct(private M1CommandService $commands) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input, bool $legacy = false): WorkOrder
    {
        $this->commands->authorize($actor, ['koordinator']);

        if ($legacy && config('m1.writer_mode') !== 'expand') {
            throw ValidationException::withMessages([
                'origin_kind' => 'Kontrak berubah. Muat ulang formulir Work Order.',
            ]);
        }

        $base = $this->validated($input);
        $base['customer_site_id'] = (int) $base['customer_site_id'];
        $base['asset_ids'] = array_map('intval', $base['asset_ids'] ?? []);
        sort($base['asset_ids']);
        $base['scheduled_at'] = $base['scheduled_at'] ?? null;

        $control = $legacy ? [] : Validator::make($input, [
            'command_key' => ['bail', 'required', 'uuid'],
            'origin_kind' => ['required', Rule::in(['ticket', 'manual'])],
            'ticket_id' => [
                'bail', 'nullable', 'required_if:origin_kind,ticket', 'prohibited_if:origin_kind,manual',
                'integer', 'exists:service_tickets,id',
            ],
            'expected_ticket_version' => [
                'bail', 'nullable', 'required_if:origin_kind,ticket', 'prohibited_if:origin_kind,manual', 'integer', 'min:0',
            ],
            'manual_reason' => [
                'bail', 'nullable', 'required_if:origin_kind,manual', 'prohibited_if:origin_kind,ticket',
                'string', 'max:10000', 'encoding:UTF-8',
            ],
            'initial_scope' => ['bail', 'required', 'array:description,acceptance_criteria'],
            'initial_scope.description' => ['bail', 'required', 'string', 'max:10000', 'encoding:UTF-8'],
            'initial_scope.acceptance_criteria' => ['bail', 'required', 'string', 'max:10000', 'encoding:UTF-8'],
        ])->validate();

        if (! $legacy) {
            $control['ticket_id'] = isset($control['ticket_id']) ? (int) $control['ticket_id'] : null;
            $control['expected_ticket_version'] = isset($control['expected_ticket_version'])
                ? (int) $control['expected_ticket_version']
                : null;
            $control['manual_reason'] = $control['manual_reason'] ?? null;
        }

        $key = $legacy ? (string) Str::uuid() : strtolower($control['command_key']);
        unset($control['command_key']);
        $payload = ['work_order' => $base, ...$control];

        $history = $this->commands->run(
            $actor,
            $key,
            $legacy ? 'work_order.legacy_create' : 'work_order.create',
            $payload,
            ['koordinator'],
            function () use ($base, $control): array {
                $ticket = empty($control['ticket_id'])
                    ? null
                    : ServiceTicket::query()->lockForUpdate()->find($control['ticket_id']);
                $site = CustomerSite::query()->sharedLock()->find($base['customer_site_id']);
                $assetIds = $base['asset_ids'];
                if ($ticket?->primary_asset_id) {
                    $assetIds[] = $ticket->primary_asset_id;
                }

                $allAssets = Asset::query()->whereIn('id', array_unique($assetIds))
                    ->orderBy('id')->lockForUpdate()->get(['id', 'customer_site_id']);
                $assets = $allAssets->whereIn('id', $base['asset_ids'])->values();

                return compact('ticket', 'site', 'assets', 'allAssets');
            },
            function (array $context, User $current) use ($base, $control, $legacy): array {
                ['ticket' => $ticket, 'site' => $site, 'assets' => $assets] = $context;

                if (! $site) {
                    throw ValidationException::withMessages(['customer_site_id' => 'Customer Site tidak tersedia.']);
                }

                if ($assets->count() !== count($base['asset_ids'])
                    || $assets->contains(fn (Asset $asset): bool => (int) $asset->customer_site_id !== (int) $site->id)) {
                    throw ValidationException::withMessages([
                        'asset_ids' => 'Pilih asset yang masih tersedia di Customer Site terpilih.',
                    ]);
                }

                $ticketFrom = $ticket?->status;
                if (! $legacy && $control['origin_kind'] === 'ticket') {
                    if (! $ticket || $ticket->lock_version !== $control['expected_ticket_version']) {
                        $this->commands->conflict('expected_ticket_version', 'TICKET_VERSION_CONFLICT');
                    }

                    if (! in_array($ticket->status, ['approved', 'in_fulfillment'], true)
                        || ! in_array($ticket->coverage_disposition, ['covered', 'no_charge', 'authorized'], true)
                        || ($ticket->coverage_disposition === 'authorized' && blank($ticket->authorization_ref))) {
                        $this->commands->conflict('ticket_id', 'TICKET_NOT_AUTHORIZED');
                    }

                    if ((int) $ticket->site_id !== (int) $site->id
                        || (int) $ticket->customer_id !== (int) $site->customer_id) {
                        throw ValidationException::withMessages([
                            'customer_site_id' => 'Site harus sama dengan site ticket yang disetujui.',
                        ]);
                    }

                    if ($ticket->primary_asset_id && ! $context['allAssets']->contains(
                        fn (Asset $asset): bool => (int) $asset->id === (int) $ticket->primary_asset_id
                            && (int) $asset->customer_site_id === (int) $site->id,
                    )) {
                        throw ValidationException::withMessages(['ticket_id' => 'Asset ticket tidak sesuai site.']);
                    }
                }

                $workOrder = new WorkOrder(collect($base)->except('asset_ids')->all());
                $workOrder->forceFill([
                    'wo_number' => 'WO-'.Str::ulid(),
                    'created_by' => $current->id,
                    'status' => 'pending',
                    'scheduled_at' => empty($base['scheduled_at'])
                        ? null
                        : Carbon::createFromFormat('!Y-m-d\TH:i', $base['scheduled_at'], config('app.timezone')),
                ]);

                if (! $legacy) {
                    $workOrder->forceFill([
                        'origin_kind' => $control['origin_kind'],
                        'ticket_id' => $control['ticket_id'],
                        'manual_reason' => $control['manual_reason'],
                        'acceptance_context_ref' => $ticket ? 'ticket:'.$ticket->id : 'manual:'.$workOrder->wo_number,
                    ]);
                }

                $workOrder->save();
                $workOrder->assets()->attach($assets->modelKeys());
                $scope = null;

                if (! $legacy) {
                    $scope = WorkOrderScopeItem::query()->create([
                        'work_order_id' => $workOrder->id,
                        'scope_no' => 1,
                        'description' => $control['initial_scope']['description'],
                        'acceptance_criteria' => $control['initial_scope']['acceptance_criteria'],
                        'is_required' => true,
                        'disposition' => 'active',
                        'created_by' => $current->id,
                        'created_at' => now(),
                    ]);

                    if ($ticket) {
                        $ticket->status = 'in_fulfillment';
                        $ticket->lock_version++;
                        $ticket->save();
                    }
                }

                return [
                    'subject_type' => 'work_order',
                    'subject_id' => $workOrder->id,
                    'to_state' => 'pending',
                    'facts' => [
                        'work_order_id' => $workOrder->id,
                        'scope_id' => $scope?->id,
                        'ticket_id' => $ticket?->id,
                        'asset_ids' => $assets->modelKeys(),
                        'ticket_from' => $ticketFrom,
                        'ticket_version' => $ticket?->lock_version,
                        'expected_ticket_version' => $control['expected_ticket_version'] ?? null,
                        'cohort' => $legacy ? 'C1' : 'C2',
                        'writer' => $legacy ? 'expand_legacy' : 'm1',
                    ],
                ];
            },
        );

        return WorkOrder::query()->visibleTo($actor)->findOrFail($history->subject_id);
    }

    /** @param array<string, mixed> $input */
    public function validated(array $input): array
    {
        return Validator::make($input, [
            'customer_site_id' => ['bail', 'required', 'integer', 'exists:customer_sites,id'],
            'title' => ['bail', 'required', 'string', 'max:150', 'encoding:UTF-8'],
            'description' => [
                'bail', 'required', 'string', 'encoding:UTF-8',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 65535) {
                        $fail('Deskripsi maksimal 65.535 byte.');
                    }
                },
            ],
            'service_type' => ['bail', 'required', 'string', Rule::in(self::SERVICE_TYPES)],
            'category' => ['bail', 'required', 'string', 'max:50', 'encoding:UTF-8'],
            'priority' => ['bail', 'required', 'string', Rule::in(self::PRIORITIES)],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'asset_ids' => ['sometimes', 'array', 'list'],
            'asset_ids.*' => ['bail', 'required', 'integer', 'distinct', 'exists:assets,id'],
        ])->validate();
    }
}
