<?php

namespace App\Services;

use App\Models\BastDocument;
use App\Models\BastVersion;
use App\Models\PrivateEvidenceFile;
use App\Models\ServicePhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BastService
{
    public function __construct(
        private readonly M1CommandService $commands,
        private readonly BastContextService $context,
        private readonly PrivateEvidenceService $files,
        private readonly ServiceReportWorkflowService $reports,
    ) {}

    public function createDraft(User $actor, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'acceptance_context_ref' => ['required', 'string', 'max:150'], ...$this->itemRules(),
        ]);
        $discovery = $this->context->discover($data['report_ids']);
        $discovery['customerIds'] = BastContextService::ids([...$discovery['customerIds'], (int) $data['customer_id']]);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.create', $data, ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context, User $current) use ($data): array {
                $this->expectedWorkOrders($context, $data);
                $reports = $this->context->lockReports($context);
                $snapshot = $this->context->eligible($context, $reports, $data['report_ids'], (int) $data['customer_id'], $data['acceptance_context_ref']);
                $this->unclaimed($snapshot);
                $document = BastDocument::query()->create(['document_number' => 'BAST-'.Str::ulid(), 'customer_id' => $data['customer_id'],
                    'acceptance_context_ref' => $data['acceptance_context_ref'], 'created_by' => $current->id, 'created_at' => now()]);
                $version = BastVersion::query()->create(['document_id' => $document->id, 'version_no' => 1, 'status' => 'draft', 'lock_version' => 0]);
                $this->writeItems($version, $data['report_ids']);

                return $this->result($version, null, 'draft', ['report_ids' => $data['report_ids']]);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    public function updateDraft(User $actor, int $versionId, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, ['expected_version' => ['required', 'integer', 'min:0'], ...$this->itemRules()]);
        $discovery = $this->context->discover($data['report_ids'], [$versionId]);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.update', ['version_id' => $versionId, 'input' => $data], ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context) use ($data, $discovery, $versionId): array {
                $this->expectedWorkOrders($context, $data);
                [$version, $document, $reports] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if ($version->status !== 'draft') {
                    $this->commands->conflict('version_id', 'BAST_STATE_CONFLICT');
                }
                $snapshot = $this->context->eligible($context, $reports, $data['report_ids'], (int) $document->customer_id, $document->acceptance_context_ref);
                $this->unclaimed($snapshot, $version->id);
                $version->items()->delete();
                $this->writeItems($version, $data['report_ids']);
                $version->increment('lock_version');

                return $this->result($version, 'draft', 'draft', ['report_ids' => $data['report_ids']]);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    public function upload(User $actor, int $versionId, UploadedFile $file, array $payload): PrivateEvidenceFile
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, ['expected_version' => ['required', 'integer', 'min:0'],
            'purpose' => ['required', Rule::in(['issued', 'decision'])], 'scope_confirmed' => ['required', 'accepted']]);
        $visible = $this->context->visibleVersion($actor, $versionId);
        $binding = $this->binding($visible, $data['expected_version'], $data['purpose']);
        $discovery = $this->context->discover([], [$versionId]);

        return $this->files->upload($actor, $file, $payload, $binding, ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context) use ($data, $discovery, $versionId, $binding): void {
                [$version, $document, $reports] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if ($version->status !== ($data['purpose'] === 'issued' ? 'draft' : 'pending') || $this->binding($version, $data['expected_version'], $data['purpose']) !== $binding) {
                    $this->commands->conflict('version_id', 'BAST_STATE_CONFLICT');
                }
                $this->context->eligible($context, $reports, $binding['report_ids'], (int) $document->customer_id, $document->acceptance_context_ref);
            });
    }

    public function issue(User $actor, int $versionId, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, ['expected_version' => ['required', 'integer', 'min:0'],
            'evidence_file_id' => ['required', 'integer', 'min:1'], 'scope_confirmed' => ['required', 'accepted'], ...$this->signerRules()]);
        $visible = $this->context->visibleVersion($actor, $versionId);
        $this->verifyEvidence($visible, [(int) $data['evidence_file_id']]);
        $discovery = $this->context->discover([], [$versionId]);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.issue', ['version_id' => $versionId, 'input' => $data], ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context, User $current) use ($data, $discovery, $versionId): array {
                [$version, $document, $reports, $items] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if ($version->status !== 'draft' || $items->isEmpty()) {
                    $this->commands->conflict('version_id', 'BAST_STATE_CONFLICT');
                }
                $snapshot = $this->context->eligible($context, $reports, BastContextService::ids($items->pluck('report_revision_id')->all()), (int) $document->customer_id, $document->acceptance_context_ref);
                $this->authority($snapshot, $data);
                $this->unclaimed($snapshot, $version->id);
                $photos = ServicePhoto::query()->whereIn('service_report_id', $items->pluck('report_revision_id'))->orderBy('id')->lockForUpdate()->get();
                $fileIds = BastContextService::ids([...$photos->pluck('evidence_file_id')->filter()->all(), (int) $data['evidence_file_id']]);
                $files = PrivateEvidenceFile::query()->whereIn('id', $fileIds)->orderBy('id')->lockForUpdate()->get();
                if ($photos->contains(fn (ServicePhoto $photo): bool => $photo->evidence_file_id === null)
                    || $files->count() !== count($fileIds) || $files->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')) {
                    $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
                }
                $this->files->lockReady($current, (int) $data['evidence_file_id'], $this->binding($version, $data['expected_version'], 'issued'));
                $version->forceFill(['status' => 'pending', 'issued_by' => $current->id, 'issued_at' => now(),
                    'issued_file_id' => $data['evidence_file_id'], 'scope_snapshot' => ['items' => $snapshot,
                        'pic' => array_intersect_key($data, array_flip(['signer_name', 'signer_capacity', 'authority_ref', 'authorized_site_ids']))],
                    'lock_version' => $version->lock_version + 1])->save();

                return $this->result($version, 'draft', 'pending', ['scope_ids' => array_column($snapshot, 'scope_item_id'), 'issued_file_id' => (int) $data['evidence_file_id']]);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    public function decide(User $actor, int $versionId, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, [
            'expected_version' => ['required', 'integer', 'min:0'], 'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'evidence_file_id' => ['required', 'integer', 'min:1'], ...$this->signerRules(),
            'decided_at' => ['required', 'date', 'regex:/[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:10000'],
            'category' => ['nullable', 'required_if:decision,rejected', Rule::in(['document_fix', 'technical_rework'])],
            'rework_scope_authors' => ['sometimes', 'array'], 'rework_scope_authors.*' => ['integer', 'min:1'],
        ]);
        $data['decided_at'] = CarbonImmutable::parse($data['decided_at'])->utc()->toISOString();
        $visible = $this->context->visibleVersion($actor, $versionId);
        $this->verifyEvidence($visible, [(int) $data['evidence_file_id']]);
        $authors = array_values($data['rework_scope_authors'] ?? []);
        $discovery = $this->context->discover([], [$versionId], $authors);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.decision', ['version_id' => $versionId, 'input' => $data], ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context, User $current) use ($data, $discovery, $versionId): array {
                [$version, $document, $reports, $items] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if ($version->status !== 'pending' || $items->isEmpty()) {
                    $this->commands->conflict('version_id', 'BAST_STATE_CONFLICT');
                }
                $snapshot = $this->context->eligible($context, $reports, BastContextService::ids($items->pluck('report_revision_id')->all()), (int) $document->customer_id, $document->acceptance_context_ref);
                $this->authority($snapshot, $data);
                $this->unclaimed($snapshot, $version->id);
                $decided = CarbonImmutable::parse($data['decided_at']);
                if ($decided->isFuture()) {
                    throw ValidationException::withMessages(['decided_at' => 'Waktu keputusan tidak boleh di masa depan.']);
                }
                $technical = $data['decision'] === 'rejected' && $data['category'] === 'technical_rework';
                $rework = $data['rework_scope_authors'] ?? [];
                if ($technical && ($rework === [] || array_diff(array_map('intval', array_keys($rework)), array_column($snapshot, 'scope_item_id')) !== [])) {
                    throw ValidationException::withMessages(['rework_scope_authors' => 'Pilih scope defect dan penulis revisi untuk setiap scope tersebut.']);
                }
                if ($technical && $context['bookings']->whereIn('visit_id', $context['visits']->whereIn('work_order_id', array_column(array_filter($snapshot, fn (array $item): bool => isset($rework[$item['scope_item_id']])), 'work_order_id'))->modelKeys())->contains('status', 'in_progress')) {
                    $this->commands->conflict('version_id', 'ACTIVE_BOOKING_BLOCKS_REWORK');
                }
                $photos = ServicePhoto::query()->whereIn('service_report_id', $items->pluck('report_revision_id'))->orderBy('id')->lockForUpdate()->get();
                $fileIds = BastContextService::ids([...$photos->pluck('evidence_file_id')->filter()->all(), (int) $version->issued_file_id, (int) $data['evidence_file_id']]);
                $files = PrivateEvidenceFile::query()->whereIn('id', $fileIds)->orderBy('id')->lockForUpdate()->get();
                if ($photos->contains(fn (ServicePhoto $photo): bool => $photo->evidence_file_id === null)
                    || $files->count() !== count($fileIds) || $files->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')) {
                    $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
                }
                $this->files->lockReady($current, (int) $data['evidence_file_id'], $this->binding($version, $data['expected_version'], 'decision'));
                $successors = [];
                if ($technical) {
                    foreach ($snapshot as $item) {
                        if (! isset($rework[$item['scope_item_id']])) {
                            continue;
                        }
                        $author = $context['resources']->get((int) $rework[$item['scope_item_id']]);
                        $wo = $context['workOrders']->get($item['work_order_id']);
                        if (! $author || ! $author->hasRole('teknisi') || ! $author->isActive() || ! $author->isApproved()
                            || ! $context['memberships']->contains(fn (object $membership): bool => (int) $membership->work_order_id === (int) $wo->id && (int) $membership->user_id === (int) $author->id)) {
                            throw ValidationException::withMessages(['rework_scope_authors' => 'Penulis revisi harus teknisi anggota WO yang aktif dan approved.']);
                        }
                        $previous = $reports->get($item['report_revision_id']);
                        $next = $this->reports->createSuccessor($previous, $author, $photos->where('service_report_id', $previous->id));
                        $successors[] = ['scope_item_id' => $item['scope_item_id'], 'previous_revision_id' => $previous->id, 'report_id' => $next->id, 'author_id' => $author->id];
                        $this->reports->transition($wo, $current, 'rework', $data['reason']);
                    }
                }
                $version->forceFill(['status' => $data['decision'], 'decided_at' => $decided, 'signer_name' => $data['signer_name'],
                    'signer_capacity' => $data['signer_capacity'], 'authority_ref' => $data['authority_ref'], 'decision_file_id' => $data['evidence_file_id'],
                    'recorded_by' => $current->id, 'recorded_at' => now(), 'reason' => $data['reason'] ?? null, 'lock_version' => $version->lock_version + 1])->save();

                return $this->result($version, 'pending', $data['decision'], ['scope_ids' => array_column($snapshot, 'scope_item_id'),
                    'decision_file_id' => (int) $data['evidence_file_id'], 'signer_name' => $data['signer_name'], 'authorized_site_ids' => $data['authorized_site_ids'],
                    'category' => $data['category'] ?? null, 'successors' => $successors], $data['reason'] ?? null);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    public function cancel(User $actor, int $versionId, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, ['expected_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:10000']]);
        $discovery = $this->context->discover([], [$versionId]);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.cancel', ['version_id' => $versionId, 'input' => $data], ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery), function (array $context) use ($discovery, $versionId, $data): array {
                [$version] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if (! in_array($version->status, ['draft', 'pending'], true)) {
                    $this->commands->conflict('version_id', 'BAST_STATE_CONFLICT');
                }
                $from = $version->status;
                $version->forceFill(['status' => 'cancelled', 'reason' => $data['reason'], 'lock_version' => $version->lock_version + 1])->save();

                return $this->result($version, $from, 'cancelled', [], $data['reason']);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    public function nextVersion(User $actor, int $versionId, array $payload): BastVersion
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, ['expected_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:10000'], ...$this->itemRules()]);
        $discovery = $this->context->discover($data['report_ids'], [$versionId]);
        $history = $this->commands->run($actor, $data['command_key'], 'bast.revise', ['version_id' => $versionId, 'input' => $data], ['koordinator'],
            fn (): array => $this->context->lockRoots($actor, $discovery), function (array $context) use ($discovery, $versionId, $data): array {
                $this->expectedWorkOrders($context, $data);
                [$previous, $document, $reports] = $this->lockedVersion($context, $discovery, $versionId, $data['expected_version']);
                if ($previous->status === 'accepted') {
                    $this->commands->conflict('version_id', 'BAST_ACCEPTED_IMMUTABLE');
                }
                $snapshot = $this->context->eligible($context, $reports, $data['report_ids'], (int) $document->customer_id, $document->acceptance_context_ref);
                $this->unclaimed($snapshot, $previous->id);
                $from = $previous->status;
                if (in_array($previous->status, ['draft', 'pending'], true)) {
                    $previous->forceFill(['status' => 'superseded', 'reason' => $data['reason'], 'lock_version' => $previous->lock_version + 1])->save();
                }
                $next = BastVersion::query()->create(['document_id' => $document->id, 'version_no' => $previous->version_no + 1,
                    'previous_version_id' => $previous->id, 'status' => 'draft', 'lock_version' => 0, 'reason' => $data['reason']]);
                $this->writeItems($next, $data['report_ids']);

                return $this->result($next, $from, 'draft', ['previous_version_id' => $previous->id, 'previous_status' => $from, 'report_ids' => $data['report_ids']], $data['reason']);
            });

        return BastVersion::query()->findOrFail($history->subject_id);
    }

    /** @param array<string, mixed> $context @return array{BastVersion, BastDocument, Collection, Collection} */
    private function lockedVersion(array $context, array $discovery, int $versionId, int $expected): array
    {
        $reports = $this->context->lockReports($context);
        $packages = $this->context->lockPackages($discovery['documentIds']);
        $version = $packages['versions']->get($versionId);
        if (! $version) {
            $this->commands->conflict('version_id', 'BAST_PARENT_SET_CHANGED');
        }
        if ($version->lock_version !== $expected || $packages['versions']->where('document_id', $version->document_id)->max('version_no') !== $version->version_no) {
            $this->commands->conflict('expected_version', 'BAST_VERSION_CONFLICT');
        }
        $items = $packages['items']->where('version_id', $version->id);
        if (array_diff($items->pluck('report_revision_id')->all(), $reports->modelKeys()) !== []) {
            $this->commands->conflict('version_id', 'BAST_PARENT_SET_CHANGED');
        }
        $version->setRelation('items', $items);

        return [$version, $packages['documents']->get($version->document_id), $reports, $items];
    }

    private function input(array $payload, array $rules): array
    {
        $data = Validator::make($payload, ['command_key' => ['required', 'uuid'], ...$rules])->validate();
        foreach (['expected_version', 'customer_id', 'evidence_file_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['report_ids', 'authorized_site_ids'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = BastContextService::ids($data[$field]);
            }
        }
        if (isset($data['scope_confirmed'])) {
            $data['scope_confirmed'] = true;
        }
        if (isset($data['work_order_versions'])) {
            $data['work_order_versions'] = array_map('intval', $data['work_order_versions']);
        }
        if (isset($data['rework_scope_authors'])) {
            $data['rework_scope_authors'] = array_map('intval', $data['rework_scope_authors']);
        }

        return $data;
    }

    private function itemRules(): array
    {
        return ['report_ids' => ['present', 'array', 'list', 'max:100'], 'report_ids.*' => ['integer', 'min:1', 'distinct'],
            'work_order_versions' => ['present', 'array'], 'work_order_versions.*' => ['integer', 'min:0']];
    }

    private function signerRules(): array
    {
        return ['signer_name' => ['required', 'string', 'max:150'], 'signer_capacity' => ['required', 'string', 'max:150'],
            'authority_ref' => ['required', 'string', 'max:180'], 'authorized_site_ids' => ['required', 'array', 'list', 'min:1'],
            'authorized_site_ids.*' => ['integer', 'min:1', 'distinct']];
    }

    private function expectedWorkOrders(array $context, array $data): void
    {
        foreach ($context['workOrders'] as $wo) {
            if (! array_key_exists($wo->id, $data['work_order_versions']) || (int) $wo->lock_version !== $data['work_order_versions'][$wo->id]) {
                $this->commands->conflict('work_order_versions', 'WORK_ORDER_VERSION_CONFLICT');
            }
        }
    }

    private function authority(array $snapshot, array $data): void
    {
        if (BastContextService::ids(array_column($snapshot, 'site_id')) !== $data['authorized_site_ids']) {
            $this->commands->conflict('authorized_site_ids', 'BAST_AUTHORITY_SCOPE_MISMATCH');
        }
    }

    private function unclaimed(array $snapshot, ?int $exceptVersion = null): void
    {
        $claims = $this->context->claims(array_column($snapshot, 'scope_item_id'));
        if ($claims->contains(fn (object $claim): bool => (int) $claim->version_id !== $exceptVersion)) {
            $this->commands->conflict('report_ids', 'BAST_SCOPE_ALREADY_CLAIMED');
        }
    }

    private function writeItems(BastVersion $version, array $reportIds): void
    {
        foreach ($reportIds as $ordinal => $reportId) {
            $version->items()->create(['report_revision_id' => $reportId, 'ordinal' => $ordinal + 1]);
        }
    }

    private function binding(BastVersion $version, int $expected, string $purpose): array
    {
        return ['purpose' => 'bast.'.$purpose, 'version_id' => $version->id, 'expected_version' => $expected,
            'report_ids' => BastContextService::ids($version->items->pluck('report_revision_id')->all()), 'scope_confirmed' => true];
    }

    public function verifyEvidence(BastVersion $version, array $extraFileIds = []): void
    {
        $reportIds = $version->items->pluck('report_revision_id');
        $fileIds = ServicePhoto::query()->whereIn('service_report_id', $reportIds)->pluck('evidence_file_id')->filter()->all();
        $fileIds = BastContextService::ids([...$fileIds, ...$extraFileIds, ...array_filter([$version->issued_file_id, $version->decision_file_id])]);
        $files = PrivateEvidenceFile::query()->whereIn('id', $fileIds)->get();
        if ($files->count() !== count($fileIds)) {
            $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
        }
        foreach ($files as $file) {
            if ($file->state !== 'ready') {
                $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
            }
            $this->files->verify($file);
        }
    }

    private function result(BastVersion $version, ?string $from, string $to, array $facts, ?string $reason = null): array
    {
        return ['subject_type' => 'bast_version', 'subject_id' => $version->id, 'from_state' => $from, 'to_state' => $to,
            'reason' => $reason, 'facts' => ['document_id' => (int) $version->document_id, 'version_id' => $version->id,
                'version_no' => $version->version_no, 'lock_version' => $version->lock_version, ...$facts]];
    }
}
