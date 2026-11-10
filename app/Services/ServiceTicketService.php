<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CustomerSite;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceTicketService
{
    public function __construct(private M1CommandService $commands) {}

    /** @param array<string, mixed> $input */
    public function intake(User $actor, array $input): ServiceTicket
    {
        $this->commands->authorize($actor, ['admin']);
        $data = Validator::make($input, [
            'command_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['request', 'incident', 'complaint'])],
            'channel' => ['bail', 'required', 'string', 'max:30', 'encoding:UTF-8'],
            'contact_label' => ['bail', 'required', 'string', 'max:150', 'encoding:UTF-8'],
            'description' => ['bail', 'required', 'string', 'max:10000', 'encoding:UTF-8'],
            'received_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'site_id' => ['nullable', 'integer', 'exists:customer_sites,id'],
        ])->validate();
        $key = strtolower($data['command_key']);
        unset($data['command_key']);
        $data['site_id'] = isset($data['site_id']) ? (int) $data['site_id'] : null;
        $data['received_at'] = $data['received_at'] ?? null;

        $history = $this->commands->run(
            $actor,
            $key,
            'ticket.intake',
            $data,
            ['admin'],
            fn (): ?CustomerSite => $data['site_id']
                ? CustomerSite::query()->sharedLock()->find($data['site_id'])
                : null,
            function (?CustomerSite $site, User $current) use ($data): array {
                if ($data['site_id'] && ! $site) {
                    throw ValidationException::withMessages(['site_id' => 'Site tidak tersedia.']);
                }

                $ticket = ServiceTicket::query()->create([
                    'ticket_number' => 'TKT-'.Str::ulid(),
                    'kind' => $data['kind'],
                    'channel' => $data['channel'],
                    'contact_label' => $data['contact_label'],
                    'description' => $data['description'],
                    'received_at' => $data['received_at']
                        ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['received_at'], 'UTC')
                        : now(),
                    'created_at' => now(),
                    'created_by' => $current->id,
                    'site_id' => $site?->id,
                    'customer_id' => $site?->customer_id,
                    'status' => 'new',
                    'coverage_disposition' => 'unknown',
                    'lock_version' => 0,
                ]);

                return [
                    'subject_type' => 'service_ticket',
                    'subject_id' => $ticket->id,
                    'to_state' => 'new',
                    'facts' => ['ticket_id' => $ticket->id],
                ];
            },
        );

        return ServiceTicket::query()->findOrFail($history->subject_id);
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, int $id, string $action, array $input): ServiceTicket
    {
        $this->commands->authorize($actor, ['koordinator']);
        abort_unless(in_array($action, ['triage', 'approve'], true), 404);

        $rules = [
            'command_key' => ['required', 'uuid'],
            'expected_ticket_version' => ['required', 'integer', 'min:0'],
        ];
        if ($action === 'triage') {
            $rules += [
                'site_id' => ['required', 'integer', 'exists:customer_sites,id'],
                'primary_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
                'coverage_disposition' => ['required', Rule::in(['covered', 'authorized', 'no_charge', 'unknown'])],
                'authorization_ref' => ['bail', 'nullable', 'string', 'max:180', 'encoding:UTF-8'],
            ];
        }

        $data = Validator::make($input, $rules)->validate();
        $key = strtolower($data['command_key']);
        unset($data['command_key']);
        $data['expected_ticket_version'] = (int) $data['expected_ticket_version'];

        if ($action === 'triage') {
            $data['site_id'] = (int) $data['site_id'];
            $data['primary_asset_id'] = isset($data['primary_asset_id']) ? (int) $data['primary_asset_id'] : null;
            $data['authorization_ref'] = $data['authorization_ref'] ?? null;
        }

        $history = $this->commands->run(
            $actor,
            $key,
            'ticket.'.$action,
            ['ticket_id' => $id, ...$data],
            ['koordinator'],
            function () use ($id, $data): array {
                $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($id);
                $siteId = $data['site_id'] ?? $ticket->site_id;
                $site = $siteId ? CustomerSite::query()->sharedLock()->find($siteId) : null;
                $assetId = array_key_exists('primary_asset_id', $data)
                    ? $data['primary_asset_id']
                    : $ticket->primary_asset_id;
                $asset = $assetId ? Asset::query()->lockForUpdate()->find($assetId) : null;

                return compact('ticket', 'site', 'asset', 'assetId');
            },
            function (array $context, User $current) use ($action, $data): array {
                ['ticket' => $ticket, 'site' => $site, 'asset' => $asset] = $context;

                if ($ticket->lock_version !== $data['expected_ticket_version']) {
                    $this->commands->conflict('expected_ticket_version', 'TICKET_VERSION_CONFLICT');
                }

                $allowed = $action === 'approve' ? ['triaged'] : ['new', 'triaged', 'waiting_information'];
                if (! in_array($ticket->status, $allowed, true)) {
                    $this->commands->conflict('ticket_id', 'TICKET_STATE_CONFLICT');
                }

                if (! $site || ($asset && (int) $asset->customer_site_id !== (int) $site->id)) {
                    throw ValidationException::withMessages(['site_id' => 'Site dan asset ticket harus konsisten.']);
                }

                if ($context['assetId'] && ! $asset) {
                    throw ValidationException::withMessages(['primary_asset_id' => 'Asset ticket tidak tersedia.']);
                }

                if ($action === 'approve'
                    && ((int) $ticket->customer_id !== (int) $site->customer_id
                        || ! in_array($ticket->coverage_disposition, ['covered', 'authorized', 'no_charge'], true)
                        || ($ticket->coverage_disposition === 'authorized' && blank($ticket->authorization_ref)))) {
                    throw ValidationException::withMessages([
                        'coverage_disposition' => 'Coverage harus jelas; authorized memerlukan referensi otorisasi biaya yang sah.',
                    ]);
                }

                $from = $ticket->status;
                if ($action === 'triage') {
                    $ticket->forceFill([
                        'site_id' => $site->id,
                        'customer_id' => $site->customer_id,
                        'primary_asset_id' => $data['primary_asset_id'],
                        'coverage_disposition' => $data['coverage_disposition'],
                        'authorization_ref' => $data['authorization_ref'],
                    ]);
                }
                $ticket->status = $action === 'approve' ? 'approved' : 'triaged';
                $ticket->lock_version++;
                $ticket->save();

                return [
                    'subject_type' => 'service_ticket',
                    'subject_id' => $ticket->id,
                    'from_state' => $from,
                    'to_state' => $ticket->status,
                    'facts' => [
                        'ticket_id' => $ticket->id,
                        'ticket_version' => $ticket->lock_version,
                        'expected_ticket_version' => $data['expected_ticket_version'],
                        'coverage_disposition' => $ticket->coverage_disposition,
                        'authorization_ref' => $ticket->authorization_ref,
                    ],
                ];
            },
        );

        return ServiceTicket::query()->findOrFail($history->subject_id);
    }
}
