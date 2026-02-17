<?php

namespace Tests\Feature;

use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvalidStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.api_key', 'cbm_prova_2026_key');
    }

    private function headers(array $extra = []): array
    {
        return array_merge([
            'Accept' => 'application/json',
            'X-API-Key' => config('app.api_key'),
        ], $extra);
    }

    public function test_resolve_occurrence_from_reported_creates_failed_command_and_does_not_change_status(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-INVALID-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'Teste invalid transition',
            'reported_at' => now(),
        ]);

        $idem = 'INVALID-RESOLVE-001';

        $res = $this->postJson(
            "/api/occurrences/{$occ->id}/resolve",
            [],
            $this->headers(['Idempotency-Key' => $idem])
        );

        $res->assertStatus(202);

        $commandId = $res->json('commandId');
        $this->assertNotEmpty($commandId);

        $occ->refresh();
        $this->assertSame(Occurrence::STATUS_REPORTED, $occ->status);

        $this->assertDatabaseHas('command_inboxes', [
            'id' => $commandId,
            'type' => 'occurrence.resolve',
            'idempotency_key' => $idem,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'entity_type' => 'occurrence',
            'entity_id' => $occ->id,
            'action' => 'occurrence.resolved',
        ]);
    }
}
