<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalIdempotencyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.api_key', 'cbm_prova_2026_key');
    }

    public function test_internal_start_with_same_idempotency_key_has_single_effect(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-RACE-INT-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'Teste race interno',
            'reported_at' => now(),
        ]);

        $idem = 'INT-RACE-001';

        $h = [
            'Accept' => 'application/json',
            'X-API-Key' => config('app.api_key'),
            'Idempotency-Key' => $idem,
        ];

        $r1 = $this->postJson("/api/occurrences/{$occ->id}/start", [], $h);
        $r2 = $this->postJson("/api/occurrences/{$occ->id}/start", [], $h);

        $r1->assertStatus(202);
        $r2->assertStatus(202);

        $this->assertSame(
            1,
            CommandInbox::query()->where('type', 'occurrence.start')->where('idempotency_key', $idem)->count()
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('entity_type', 'occurrence')
                ->where('entity_id', $occ->id)
                ->where('action', 'occurrence.started')
                ->count()
        );
    }
}
