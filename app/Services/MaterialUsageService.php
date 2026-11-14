<?php

namespace App\Services;

use App\Models\MaterialUsage;
use App\Models\ServiceReport;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterialUsageService
{
    public function __construct(private readonly M1CommandService $commands, private readonly ReportContextService $context) {}

    public function record(User $actor, int $workOrderId, int $reportId, array $payload): MaterialUsage
    {
        $this->commands->authorize($actor, ['teknisi']);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_report_version' => ['required', 'integer', 'min:0'],
            'movement_kind' => ['required', Rule::in(['consume', 'return', 'reversal'])],
            'adjusts_usage_id' => ['nullable', 'integer', 'min:1', 'required_unless:movement_kind,consume', 'prohibited_if:movement_kind,consume'],
            'item_description' => ['required', 'string', 'max:180'], 'unit_code' => ['required', 'string', 'max:24'],
            'quantity' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,3})?\z/'],
            'supply_source' => ['required', Rule::in(['company', 'customer', 'unknown'])],
            'disposition' => ['required', Rule::in(['pending', 'covered', 'customer_supplied', 'authorized_extra'])],
            'reason' => ['nullable', 'string', 'max:10000', 'required_unless:movement_kind,consume'],
        ])->validate();
        $key = $data['command_key'];
        unset($data['command_key']);
        $data['expected_report_version'] = (int) $data['expected_report_version'];
        $data['adjusts_usage_id'] = isset($data['adjusts_usage_id']) ? (int) $data['adjusts_usage_id'] : null;
        $quantity = self::milliUnits($data['quantity']);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah material harus lebih besar dari nol.']);
        }
        $data['quantity'] = self::decimal($quantity);
        $history = $this->commands->run($actor, $key, 'material.record', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['teknisi'], fn (): array => $this->context->lockRoots($actor, $workOrderId, $reportId, false),
            function (array $context, User $current) use ($data, $reportId, $quantity): array {
                // A1 suffix: material parent and adjustments precede report locks.
                $reportHint = ServiceReport::query()->where('work_order_id', $context['workOrder']->id)->findOrFail($reportId);
                $parent = null;
                if ($data['adjusts_usage_id'] !== null) {
                    $parent = MaterialUsage::query()->where('scope_item_id', $reportHint->scope_item_id)
                        ->lockForUpdate()->findOrFail($data['adjusts_usage_id']);
                    if ($parent->movement_kind !== 'consume') {
                        $this->commands->conflict('adjusts_usage_id', 'MATERIAL_PARENT_MUST_BE_CONSUME');
                    }
                    foreach (['unit_code', 'item_description', 'supply_source', 'disposition'] as $field) {
                        if ($parent->{$field} !== $data[$field]) {
                            $this->commands->conflict($field, 'MATERIAL_PARENT_MISMATCH');
                        }
                    }
                    $adjustments = $parent->adjustments()->orderBy('id')->lockForUpdate()->get();
                    $used = 0;
                    foreach ($adjustments as $adjustment) {
                        $used += self::milliUnits($adjustment->quantity);
                    }
                    if ($used + $quantity > self::milliUnits($parent->quantity)) {
                        $this->commands->conflict('quantity', 'MATERIAL_RETURN_EXCEEDS_USAGE');
                    }
                }
                $report = $this->context->draft($context, $reportId, $data['expected_report_version']);
                $usage = MaterialUsage::query()->create([
                    'scope_item_id' => $report->scope_item_id, 'item_description' => $data['item_description'],
                    'unit_code' => $data['unit_code'], 'quantity' => $data['quantity'], 'movement_kind' => $data['movement_kind'],
                    'adjusts_usage_id' => $parent?->id, 'supply_source' => $data['supply_source'],
                    'disposition' => $data['disposition'], 'recorded_by' => $current->id, 'recorded_at' => now(), 'reason' => $data['reason'] ?? null,
                ]);
                $report->increment('lock_version');

                return ['subject_type' => 'material_usage', 'subject_id' => $usage->id, 'to_state' => $usage->movement_kind,
                    'reason' => $usage->reason, 'facts' => ['scope_item_id' => $report->scope_item_id,
                        'report_id' => $report->id, 'movement_id' => $usage->id, 'parent_movement_id' => $parent?->id,
                        'quantity' => $usage->quantity, 'unit_code' => $usage->unit_code, 'lock_version' => $report->lock_version]];
            });

        return MaterialUsage::query()->findOrFail($history->subject_id);
    }

    public static function milliUnits(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function decimal(int $quantity): string
    {
        return intdiv($quantity, 1000).'.'.str_pad((string) ($quantity % 1000), 3, '0', STR_PAD_LEFT);
    }
}
