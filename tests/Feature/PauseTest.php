<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Feature;

use AlveBy\QueueManager\Facades\QueueManager;
use AlveBy\QueueManager\Queue\PausableRedisQueue;
use AlveBy\QueueManager\Tests\Concerns\InteractsWithRedis;
use AlveBy\QueueManager\Tests\Fixtures\ExportJob;
use AlveBy\QueueManager\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class PauseTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRedis();
    }

    protected function tearDown(): void
    {
        $this->tearDownRedis();

        parent::tearDown();
    }

    public function test_the_redis_connector_is_swapped_for_a_pausable_one(): void
    {
        $this->assertInstanceOf(PausableRedisQueue::class, Queue::connection('redis'));
    }

    public function test_a_paused_queue_yields_no_jobs(): void
    {
        dispatch(new ExportJob);

        QueueManager::pause('default');

        $this->assertTrue(QueueManager::isPaused('default'));
        $this->assertNull(Queue::connection('redis')->pop('default'));

        // Nothing was consumed or moved.
        $this->assertSame(1, QueueManager::counts('default')['pending']);
    }

    public function test_resuming_lets_jobs_through_again(): void
    {
        dispatch(new ExportJob);

        QueueManager::pause('default');
        $this->assertNull(Queue::connection('redis')->pop('default'));

        QueueManager::resume('default');

        $this->assertFalse(QueueManager::isPaused('default'));
        $this->assertNotNull(Queue::connection('redis')->pop('default'));
    }

    public function test_pausing_one_queue_leaves_others_running(): void
    {
        Queue::connection('redis')->pushOn('reports', new ExportJob);
        dispatch(new ExportJob);

        QueueManager::pause('reports');

        $this->assertNull(Queue::connection('redis')->pop('reports'));
        $this->assertNotNull(Queue::connection('redis')->pop('default'));
    }

    public function test_a_timed_pause_expires_on_its_own(): void
    {
        dispatch(new ExportJob);

        QueueManager::pause('default', time() - 1);

        $this->assertFalse(QueueManager::isPaused('default'));
        $this->assertNotNull(Queue::connection('redis')->pop('default'));
    }

    public function test_it_records_why_and_until_when_a_queue_was_paused(): void
    {
        $until = time() + 600;

        $paused = QueueManager::pause('default', $until, 'deploying');

        $this->assertSame('default', $paused->queue);
        $this->assertSame('redis', $paused->connection);
        $this->assertSame($until, $paused->until);
        $this->assertSame('deploying', $paused->reason);

        $listed = QueueManager::pausedQueues();

        $this->assertCount(1, $listed);
        $this->assertSame('deploying', $listed[0]->reason);
    }

    public function test_resume_all_clears_everything(): void
    {
        QueueManager::pause('default');
        QueueManager::pause('reports');

        $this->assertGreaterThanOrEqual(2, QueueManager::resumeAll());
        $this->assertSame([], QueueManager::pausedQueues());
    }

    public function test_it_agrees_with_the_frameworks_own_pause_when_available(): void
    {
        $queue = $this->app->make('queue');

        if (! method_exists($queue, 'isPaused')) {
            $this->markTestSkipped('This Laravel version has no native queue pausing.');
        }

        QueueManager::pause('default');
        $this->assertTrue($queue->isPaused('redis', 'default'));

        QueueManager::resume('default');
        $this->assertFalse($queue->isPaused('redis', 'default'));
    }

    public function test_it_sees_a_queue_paused_by_the_framework(): void
    {
        $queue = $this->app->make('queue');

        if (! method_exists($queue, 'pause')) {
            $this->markTestSkipped('This Laravel version has no native queue pausing.');
        }

        $queue->pause('redis', 'default');

        $this->assertTrue(QueueManager::isPaused('default'));

        QueueManager::resume('default');

        $this->assertFalse(QueueManager::isPaused('default'));
    }
}
