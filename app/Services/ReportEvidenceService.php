<?php

namespace App\Services;

use App\Models\PrivateEvidenceFile;
use App\Models\ServicePhoto;
use App\Models\ServiceReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportEvidenceService
{
    public function __construct(
        private readonly M1CommandService $commands,
        private readonly ReportContextService $context,
        private readonly PrivateEvidenceService $files,
    ) {}

    public function upload(User $actor, int $workOrderId, int $reportId, UploadedFile $file, array $payload): PrivateEvidenceFile
    {
        $this->commands->authorize($actor, ['teknisi']);
        $data = Validator::make($payload, ['expected_report_version' => ['required', 'integer', 'min:0']])->validate();
        $binding = ['purpose' => 'report', 'work_order_id' => $workOrderId, 'report_id' => $reportId,
            'expected_report_version' => (int) $data['expected_report_version']];

        return $this->files->upload($actor, $file, $payload, $binding, ['teknisi'],
            fn (): array => $this->context->lockRoots($actor, $workOrderId, $reportId),
            fn (array $context): ServiceReport => $this->context->draft($context, $reportId, $binding['expected_report_version']));
    }

    public function attach(User $actor, int $workOrderId, int $reportId, array $payload): ServicePhoto
    {
        $this->commands->authorize($actor, ['teknisi']);
        $this->context->visibleReport($actor, $workOrderId, $reportId);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_report_version' => ['required', 'integer', 'min:0'],
            'evidence_file_id' => ['required', 'integer', 'min:1'], 'phase' => ['required', Rule::in(['before', 'after', 'other'])],
            'caption' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $key = $data['command_key'];
        unset($data['command_key']);
        $data['expected_report_version'] = (int) $data['expected_report_version'];
        $data['evidence_file_id'] = (int) $data['evidence_file_id'];
        $file = PrivateEvidenceFile::query()->where('uploaded_by', $actor->id)->findOrFail($data['evidence_file_id']);
        $this->files->verify($file);
        $history = $this->commands->run($actor, $key, 'report.evidence.attach', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['teknisi'], fn (): array => $this->context->lockRoots($actor, $workOrderId, $reportId),
            function (array $context, User $current) use ($data, $workOrderId, $reportId): array {
                $report = $this->context->draft($context, $reportId, $data['expected_report_version']);
                $file = $this->files->lockReady($current, $data['evidence_file_id'], [
                    'purpose' => 'report', 'work_order_id' => $workOrderId, 'report_id' => $reportId,
                    'expected_report_version' => $data['expected_report_version'],
                ]);
                $photo = new ServicePhoto;
                $photo->forceFill(['service_report_id' => $report->id, 'evidence_file_id' => $file->id,
                    'uploaded_by' => $current->id, 'storage_path' => $file->storage_key, 'phase' => $data['phase'], 'caption' => $data['caption'] ?? null])->save();
                $report->increment('lock_version');

                return ['subject_type' => 'service_photo', 'subject_id' => $photo->id, 'to_state' => 'attached',
                    'facts' => ['report_id' => $report->id, 'scope_item_id' => $report->scope_item_id,
                        'file_id' => $file->id, 'sha256' => $file->sha256, 'lock_version' => $report->lock_version]];
            });

        return ServicePhoto::query()->where('service_report_id', $reportId)->findOrFail($history->subject_id);
    }

    public function detach(User $actor, int $workOrderId, int $reportId, int $photoId, array $payload): ServiceReport
    {
        $this->commands->authorize($actor, ['teknisi']);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_report_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $data['expected_report_version'] = (int) $data['expected_report_version'];
        $history = $this->commands->run($actor, $data['command_key'], 'report.evidence.detach', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'photo_id' => $photoId, 'input' => $data,
        ], ['teknisi'], fn (): array => $this->context->lockRoots($actor, $workOrderId, $reportId),
            function (array $context) use ($data, $reportId, $photoId): array {
                $report = $this->context->draft($context, $reportId, $data['expected_report_version']);
                $photo = $report->photos()->lockForUpdate()->findOrFail($photoId);
                $file = $photo->evidence_file_id ? PrivateEvidenceFile::query()->lockForUpdate()->findOrFail($photo->evidence_file_id) : null;
                $facts = ['report_id' => $report->id, 'scope_item_id' => $report->scope_item_id,
                    'photo_id' => $photo->id, 'file_id' => $file?->id, 'sha256' => $file?->sha256,
                    'phase' => $photo->phase, 'caption' => $photo->caption, 'file_retained' => true];
                $photo->delete();
                $report->increment('lock_version');

                return ['subject_type' => 'service_report', 'subject_id' => $report->id, 'from_state' => 'draft', 'to_state' => 'draft',
                    'reason' => $data['reason'], 'facts' => [...$facts, 'lock_version' => $report->lock_version]];
            });

        return ServiceReport::query()->findOrFail($history->subject_id);
    }
}
