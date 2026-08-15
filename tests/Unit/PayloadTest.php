<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Unit;

use AlveBy\QueueManager\Support\Payload;
use PHPUnit\Framework\TestCase;

class PayloadTest extends TestCase
{
    public function test_it_matches_a_payload_by_uuid(): void
    {
        $raw = json_encode(['uuid' => 'abc-123', 'displayName' => 'App\\Jobs\\Export']);

        $this->assertTrue(Payload::matches($raw, 'abc-123'));
        $this->assertFalse(Payload::matches($raw, 'abc-124'));
    }

    public function test_it_matches_regardless_of_key_order(): void
    {
        // Redis re-encodes reserved payloads through cjson, which does not
        // preserve key order.
        $raw = json_encode(['attempts' => 1, 'displayName' => 'X', 'uuid' => 'abc-123']);

        $this->assertTrue(Payload::matches($raw, 'abc-123'));
    }

    public function test_a_uuid_appearing_elsewhere_in_the_payload_is_not_a_match(): void
    {
        $raw = json_encode(['uuid' => 'other', 'displayName' => 'abc-123']);

        $this->assertFalse(Payload::matches($raw, 'abc-123'));
    }

    public function test_it_reads_the_display_name(): void
    {
        $this->assertSame('App\\Jobs\\Export', Payload::displayName(['displayName' => 'App\\Jobs\\Export']));
        $this->assertSame('App\\Jobs\\Export', Payload::displayName(['data' => ['commandName' => 'App\\Jobs\\Export']]));
        $this->assertSame('unknown', Payload::displayName([]));
    }

    public function test_it_detects_command_bus_jobs(): void
    {
        $this->assertTrue(Payload::goesThroughCommandBus(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call']));
        $this->assertFalse(Payload::goesThroughCommandBus(['job' => 'App\\Handlers\\Legacy@handle']));
        $this->assertFalse(Payload::goesThroughCommandBus([]));
    }

    public function test_it_pulls_a_batch_id_out_of_a_serialized_command(): void
    {
        $command = 'O:8:"stdClass":1:{s:7:"batchId";s:36:"9b1c0f1e-0000-4000-8000-000000000000";}';

        $this->assertSame(
            '9b1c0f1e-0000-4000-8000-000000000000',
            Payload::batchId(['data' => ['command' => $command]]),
        );
    }

    public function test_batch_id_is_null_when_absent(): void
    {
        $this->assertNull(Payload::batchId(['data' => ['command' => 'O:8:"stdClass":0:{}']]));
        $this->assertNull(Payload::batchId([]));
    }

    public function test_it_survives_a_corrupt_payload(): void
    {
        $this->assertNull(Payload::decode('{not json'));
        $this->assertFalse(Payload::matches('{not json', 'abc'));
    }
}
