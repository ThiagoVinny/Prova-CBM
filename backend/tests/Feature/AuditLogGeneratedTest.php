<?php

namespace Tests\Feature;

use App\Jobs\ProcessOccurrenceFinishCommand;
use App\Models\AuditLog;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogGeneratedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.api_key', 'cbm_prova_2026_key');
    }

    public function test_audit_log_is_created_when_occurrence_resolved(): void
    {
        $occ = Occurrence::create([
            'external_id' => 'EXT-AUDIT-001',
            'type' => 'incendio_urbano',
            'status' => Occurrence::STATUS_IN_PROGRESS,
            'description' => 'Teste audit',
            'reported_at' => now(),
        ]);

        $cmd = CommandInbox::create([
            'idempotency_key' => 'AUDIT-IDEMP-001',
            'source' => 'operador_web',
            'type' => 'occurrence.resolve',
            'payload' => ['occurrenceId' => $occ->id],
            'status' => 'pending',
        ]);

        (new ProcessOccurrenceFinishCommand($cmd->id))->handle();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'occurrence',
            'entity_id' => $occ->id,
            'action' => 'occurrence.resolved',
        ]);

        $log = AuditLog::query()
            ->where('entity_type', 'occurrence')
            ->where('entity_id', $occ->id)
            ->where('action', 'occurrence.resolved')
            ->firstOrFail();

        $this->assertSame(Occurrence::STATUS_IN_PROGRESS, $log->before['status'] ?? null);
        $this->assertSame(Occurrence::STATUS_RESOLVED, $log->after['status'] ?? null);
    }
}
