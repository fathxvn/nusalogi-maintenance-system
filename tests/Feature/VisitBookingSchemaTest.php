<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitBookingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_and_booking_relations_can_be_read_from_database(): void
    {
        $booking = $this->booking()->fresh();
        $visit = $booking->visit;
        $workOrder = $visit->workOrder;

        $this->assertTrue(
            $workOrder->visits()->sole()->is($visit)
        );

        $this->assertTrue(
            $visit->bookings()->sole()->is($booking)
        );

        $this->assertTrue(
            $visit->creator->is($booking->confirmer)
        );

        $this->assertTrue(
            $workOrder->technicians()->sole()->is($booking->technician)
        );

        $this->assertNull($visit->previousVisit);
        $this->assertNull($visit->successorVisit);
        $this->assertNull($booking->actual_start_at);
        $this->assertNull($booking->actual_end_at);
        $this->assertSame('confirmed', $visit->status);
        $this->assertSame('confirmed', $booking->status);
    }

    #[DataProvider('invalidIntervals')]
    public function test_database_rejects_an_invalid_visit_interval(
        string $endsAt
    ): void {
        $visit = $this->booking()->visit;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('work_visits_interval_check');

        $visit->update([
            'starts_at' => '2026-11-12 02:00:00',
            'ends_at' => $endsAt,
        ]);
    }

    public static function invalidIntervals(): array
    {
        return [
            'zero duration' => ['2026-11-12 02:00:00'],
            'end before start' => ['2026-11-12 01:00:00'],
        ];
    }

    public function test_database_rejects_duplicate_technician_on_same_visit(): void
    {
        $booking = $this->booking();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'visit_bookings_visit_id_technician_id_unique'
        );

        VisitBooking::factory()
            ->for($booking->visit, 'visit')
            ->create([
                'technician_id' => $booking->technician_id,
                'confirmed_by' => $booking->confirmed_by,
            ]);
    }

    private function booking(): VisitBooking
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $technician = User::factory()->create([
            'role' => 'teknisi',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Visit Testing',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Office',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Permintaan operasional untuk fixture pengujian',
                'initial_scope' => [
                    'description' => 'Pulihkan tampilan kamera',
                    'acceptance_criteria' => 'Live feed tampil pada NVR',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pemeriksaan kamera',
                'description' => 'Kamera tidak menampilkan gambar',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ]
        );

        $workOrder->technicians()->attach($technician->id, [
            'assigned_at' => now(),
        ]);

        $workOrder->forceFill(['status' => 'assigned'])->save();

        $visit = WorkVisit::factory()
            ->for($workOrder, 'workOrder')
            ->create([
                'created_by' => $coordinator->id,
                'status' => 'confirmed',
            ]);

        return VisitBooking::factory()
            ->for($visit, 'visit')
            ->create([
                'technician_id' => $technician->id,
                'confirmed_by' => $coordinator->id,
            ]);
    }
}
