<?php

namespace Tests\Feature;

use App\Jobs\ProcessOccurrenceCommand;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencySimulationTest extends TestCase
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

    public function test_two_commands_same_external_id_results_in_one_occurrence(): void
    {
        $externalId = 'EXT-TEST-RACE-001';

        $payloadA = [
            'externalId' => $externalId,
            'type' => 'incendio_urbano',
            'description' => 'Evento A',
            'reportedAt' => now()->toIso8601String(),
        ];

        $payloadB = [
            'externalId' => $externalId,
            'type' => 'incendio_urbano',
            'description' => 'Evento B',
            'reportedAt' => now()->toIso8601String(),
        ];

        $r1 = $this->postJson('/api/integrations/occurrences', $payloadA, $this->headers('RACE-KEY-001'));
        $r2 = $this->postJson('/api/integrations/occurrences', $payloadB, $this->headers('RACE-KEY-002'));

        $r1->assertStatus(202);
        $r2->assertStatus(202);

        (new ProcessOccurrenceCommand($r1->json('commandId')))->handle();
        (new ProcessOccurrenceCommand($r2->json('commandId')))->handle();

        $this->assertSame(
            1,
            Occurrence::query()->where('external_id', $externalId)->count()
        );
    }
}
