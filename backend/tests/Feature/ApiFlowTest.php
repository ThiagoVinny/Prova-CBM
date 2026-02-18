<?php

namespace Tests\Feature;

use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFlowTest extends TestCase
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
            'Idempotency-Key' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_start_occurrence_requires_idempotency_key(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-START-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'Teste',
            'reported_at' => now(),
        ]);

        $res = $this->postJson("/api/occurrences/{$occ->id}/start", [], [
            'Accept' => 'application/json',
            'X-API-Key' => config('app.api_key'),
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['message' => 'Idempotency-Key ausente']);
    }

    public function test_start_occurrence_is_idempotent_and_transitions_to_in_progress(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-START-002',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'Teste',
            'reported_at' => now(),
        ]);

        $idem = 'START-KEY-TEST-001';

        $res1 = $this->postJson("/api/occurrences/{$occ->id}/start", [], $this->headers([
            'Idempotency-Key' => $idem,
        ]));

        $res1->assertStatus(202);
        $cmdId1 = $res1->json('commandId');
        $this->assertNotEmpty($cmdId1);

        $occ->refresh();
        $this->assertSame(Occurrence::STATUS_IN_PROGRESS, $occ->status);

        $this->assertDatabaseHas('command_inboxes', [
            'id' => $cmdId1,
            'type' => 'occurrence.start',
            'idempotency_key' => $idem,
            'status' => 'processed',
        ]);

        $res2 = $this->postJson("/api/occurrences/{$occ->id}/start", [], $this->headers([
            'Idempotency-Key' => $idem,
        ]));

        $res2->assertStatus(202);
        $this->assertSame($cmdId1, $res2->json('commandId'));

        $this->assertSame(
            1,
            CommandInbox::query()->where('type', 'occurrence.start')->where('idempotency_key', $idem)->count()
        );
    }

    public function test_finish_occurrence_transitions_to_resolved_and_is_idempotent(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-FINISH-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_IN_PROGRESS,
            'description' => 'Teste',
            'reported_at' => now(),
        ]);

        $idem = 'FINISH-KEY-TEST-001';

        $res1 = $this->postJson("/api/occurrences/{$occ->id}/resolve", [], $this->headers([
            'Idempotency-Key' => $idem,
        ]));

        $res1->assertStatus(202);
        $cmdId1 = $res1->json('commandId');
        $this->assertNotEmpty($cmdId1);

        $occ->refresh();
        $this->assertSame(Occurrence::STATUS_RESOLVED, $occ->status);

        $res2 = $this->postJson("/api/occurrences/{$occ->id}/resolve", [], $this->headers([
            'Idempotency-Key' => $idem,
        ]));

        $res2->assertStatus(202);
        $this->assertSame($cmdId1, $res2->json('commandId'));
    }

    public function test_dispatch_create_and_status_transition(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-DISP-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_IN_PROGRESS,
            'description' => 'Teste',
            'reported_at' => now(),
        ]);

        // create agora é async → 202 + commandId
        $create = $this->postJson(
            "/api/occurrences/{$occ->id}/dispatches",
            ['resourceCode' => 'ABT-12'],
            $this->headers(['Idempotency-Key' => 'DISP-KEY-001'])
        );

        $create->assertStatus(202);

        $cmdId = $create->json('commandId');
        $this->assertNotEmpty($cmdId);

        $cmd = CommandInbox::query()->findOrFail($cmdId);

        // como os testes usam queue sync, o job já executou e o payload já tem dispatchId
        $dispatchId = (string) (($cmd->payload ?? [])['dispatchId'] ?? '');
        $this->assertNotEmpty($dispatchId);

        // status também async → 202
        $update1 = $this->patchJson(
            "/api/dispatches/{$dispatchId}/status",
            ['status' => 'en_route'],
            $this->headers(['Idempotency-Key' => 'DISP-STATUS-001'])
        );

        $this->assertContains($update1->getStatusCode(), [200, 202]);

        $update2 = $this->patchJson(
            "/api/dispatches/{$dispatchId}/status",
            ['status' => 'en_route'],
            $this->headers(['Idempotency-Key' => 'DISP-STATUS-002'])
        );

        $this->assertContains($update2->getStatusCode(), [200, 202, 409, 422]);

        $this->assertDatabaseHas('dispatches', [
            'id' => $dispatchId,
            'status' => 'en_route',
        ]);
    }

    public function test_api_key_is_required(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-TEST-AUTH-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'Teste',
            'reported_at' => now(),
        ]);

        $res = $this->postJson("/api/occurrences/{$occ->id}/start", [], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'AUTH-KEY-001',
        ]);

        $this->assertContains($res->getStatusCode(), [401, 403]);
    }
}
