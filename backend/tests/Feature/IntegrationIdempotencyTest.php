<?php

namespace Tests\Feature;

use App\Jobs\ProcessOccurrenceCommand;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.api_key', 'cbm_prova_2026_key');
    }

    private function headers(string $idem): array
    {
        return [
            'Accept' => 'application/json',
            'X-API-Key' => config('app.api_key'),
            'Idempotency-Key' => $idem,
        ];
    }

    public function test_integration_is_idempotent_and_does_not_duplicate_occurrence(): void
    {
        $payload = [
            'externalId' => 'EXT-TEST-IDEMP-001',
            'type' => 'incendio_urbano',
            'description' => 'Teste integração',
            'reportedAt' => now()->toIso8601String(),
        ];

        $idem = 'IDEMP-EXT-001';

        $r1 = $this->postJson('/api/integrations/occurrences', $payload, $this->headers($idem));
        $r1->assertStatus(202);
        $cmdId1 = $r1->json('commandId');
        $this->assertNotEmpty($cmdId1);

        (new ProcessOccurrenceCommand($cmdId1))->handle();

        $this->assertSame(
            1,
            Occurrence::query()->where('external_id', $payload['externalId'])->count()
        );

        $r2 = $this->postJson('/api/integrations/occurrences', $payload, $this->headers($idem));
        $r2->assertStatus(202);
        $this->assertSame($cmdId1, $r2->json('commandId'));

        $this->assertSame(
            1,
            CommandInbox::query()
                ->where('type', 'occurrence.created')
                ->where('idempotency_key', $idem)
                ->count()
        );

        $this->assertSame(
            1,
            Occurrence::query()->where('external_id', $payload['externalId'])->count()
        );
    }
}
