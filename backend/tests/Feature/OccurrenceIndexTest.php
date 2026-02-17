<?php

namespace Tests\Feature;

use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OccurrenceIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.api_key', 'cbm_prova_2026_key');
        config()->set('app.timezone', 'America/Maceio');
        date_default_timezone_set('America/Maceio');
    }

    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-API-Key' => config('app.api_key'),
        ];
    }

    public function test_index_lists_and_filters_occurrences(): void
    {
        Occurrence::create([
            'external_id' => 'EXT-F1',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_REPORTED,
            'description' => 'A',
            'reported_at' => now(),
        ]);

        Occurrence::create([
            'external_id' => 'EXT-F2',
            'type' => 'resgate_veicular',
            'status' => Occurrence::STATUS_IN_PROGRESS,
            'description' => 'B',
            'reported_at' => now(),
        ]);

        $all = $this->getJson('/api/occurrences', $this->headers());
        $all->assertOk();
        $this->assertGreaterThanOrEqual(2, count($all->json('data')));

        $byStatus = $this->getJson('/api/occurrences?status=in_progress', $this->headers());
        $byStatus->assertOk();
        $this->assertCount(1, $byStatus->json('data'));
        $this->assertSame('EXT-F2', $byStatus->json('data.0.externalId'));

        $byType = $this->getJson('/api/occurrences?type=incendio_urbano', $this->headers());
        $byType->assertOk();
        $this->assertCount(1, $byType->json('data'));
        $this->assertSame('EXT-F1', $byType->json('data.0.externalId'));
    }
}
