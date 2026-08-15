<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Unit;

use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\Keys;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KeysTest extends TestCase
{
    public function test_queue_keys_match_the_framework(): void
    {
        $keys = new Keys('lqm');

        $this->assertSame('queues:export', $keys->queue('export'));
        $this->assertSame('queues:export:notify', $keys->notify('export'));
        $this->assertSame('queues:export:delayed', $keys->delayed('export'));
        $this->assertSame('queues:export:reserved', $keys->reserved('export'));
    }

    public function test_state_maps_to_the_right_structure(): void
    {
        $keys = new Keys('lqm');

        $this->assertSame('queues:export', $keys->forState('export', JobState::Pending));
        $this->assertSame('queues:export:delayed', $keys->forState('export', JobState::Delayed));
        $this->assertSame('queues:export:reserved', $keys->forState('export', JobState::Reserved));
    }

    public function test_index_only_states_have_no_structure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Keys('lqm'))->forState('export', JobState::Completed);
    }

    public function test_package_keys_use_the_prefix(): void
    {
        $keys = new Keys('custom');

        $this->assertSame('custom:paused', $keys->paused());
        $this->assertSame('custom:cancelled:abc', $keys->cancelled('abc'));
        $this->assertSame('custom:job:abc', $keys->job('abc'));
        $this->assertSame('custom:tag:client:1', $keys->tag('client:1'));
    }
}
