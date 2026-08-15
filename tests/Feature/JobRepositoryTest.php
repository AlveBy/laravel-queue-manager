<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Feature;

use AlveBy\QueueManager\Contracts\JobRepository;
use AlveBy\QueueManager\Facades\QueueManager;
use AlveBy\QueueManager\Manager;
use AlveBy\QueueManager\Redis\QueueDiscovery;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Tests\Concerns\InteractsWithRedis;
use AlveBy\QueueManager\Tests\Fixtures\ExportJob;
use AlveBy\QueueManager\Tests\TestCase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class JobRepositoryTest extends TestCase
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

    public function test_it_finds_a_pending_job_by_uuid(): void
    {
        $uuid = $this->dispatchJob();

        $job = QueueManager::find($uuid);

        $this->assertNotNull($job);
        $this->assertSame($uuid, $job->uuid);
        $this->assertSame('default', $job->queue);
        $this->assertSame(JobState::Pending, $job->state);
        $this->assertSame(ExportJob::class, $job->name);
    }

    public function test_it_finds_a_delayed_job_and_reports_when_it_is_due(): void
    {
        $uuid = $this->dispatchJob(delay: 300);

        $job = QueueManager::find($uuid);

        $this->assertNotNull($job);
        $this->assertSame(JobState::Delayed, $job->state);
        $this->assertGreaterThan(time() + 250, (int) $job->availableAt);
    }

    public function test_it_finds_a_reserved_job(): void
    {
        $uuid = $this->dispatchJob();

        Queue::connection('redis')->pop('default');

        $job = QueueManager::find($uuid);

        $this->assertNotNull($job);
        $this->assertSame(JobState::Reserved, $job->state);
        $this->assertSame(1, $job->attempts);
    }

    public function test_deleting_a_pending_job_keeps_the_notify_list_in_sync(): void
    {
        $keep = $this->dispatchJob('keep');
        $drop = $this->dispatchJob('drop');

        $this->assertSame(2, (int) Redis::connection()->llen('queues:default'));
        $this->assertSame(2, (int) Redis::connection()->llen('queues:default:notify'));

        $removed = QueueManager::delete($drop);

        $this->assertNotNull($removed);
        $this->assertSame($drop, $removed->uuid);

        // The notify list must shrink with the queue, or workers wake up for
        // jobs that are not there.
        $this->assertSame(1, (int) Redis::connection()->llen('queues:default'));
        $this->assertSame(1, (int) Redis::connection()->llen('queues:default:notify'));

        $this->assertNull(QueueManager::find($drop));
        $this->assertNotNull(QueueManager::find($keep));
    }

    public function test_deleting_a_delayed_job_removes_it_from_the_sorted_set(): void
    {
        $uuid = $this->dispatchJob(delay: 300);

        $this->assertSame(1, (int) Redis::connection()->zcard('queues:default:delayed'));

        $this->assertNotNull(QueueManager::delete($uuid));
        $this->assertSame(0, (int) Redis::connection()->zcard('queues:default:delayed'));
    }

    public function test_deleting_an_unknown_uuid_returns_null(): void
    {
        $this->assertNull(QueueManager::delete('does-not-exist'));
    }

    public function test_it_counts_each_structure(): void
    {
        $this->dispatchJob();
        $this->dispatchJob();
        $this->dispatchJob(delay: 300);

        $this->assertSame(
            ['pending' => 2, 'delayed' => 1, 'reserved' => 0],
            QueueManager::counts('default'),
        );
    }

    public function test_it_lists_jobs_in_a_queue_in_pop_order(): void
    {
        $first = $this->dispatchJob('a');
        $second = $this->dispatchJob('b');

        $jobs = QueueManager::search()->queue('default')->state('pending')->get();

        $this->assertCount(2, $jobs);
        $this->assertSame([$first, $second], $jobs->pluck('uuid')->all());
        $this->assertSame([ExportJob::class, ExportJob::class], $jobs->pluck('name')->all());
    }

    public function test_listing_respects_limit_and_offset(): void
    {
        $this->dispatchJob('a');
        $second = $this->dispatchJob('b');
        $this->dispatchJob('c');

        $jobs = QueueManager::search()->queue('default')->state('pending')->limit(1)->offset(1)->get();

        $this->assertCount(1, $jobs);
        $this->assertSame($second, $jobs->first()->uuid);
    }

    public function test_it_promotes_a_delayed_job_to_ready(): void
    {
        $uuid = $this->dispatchJob(delay: 3600);

        $promoted = QueueManager::runNow($uuid);

        $this->assertNotNull($promoted);
        $this->assertSame(0, (int) Redis::connection()->zcard('queues:default:delayed'));
        $this->assertSame(1, (int) Redis::connection()->llen('queues:default'));
        $this->assertSame(1, (int) Redis::connection()->llen('queues:default:notify'));

        $this->assertSame(JobState::Pending, QueueManager::find($uuid)->state);
    }

    public function test_it_reschedules_a_pending_job_into_the_future(): void
    {
        $uuid = $this->dispatchJob();

        $when = time() + 900;

        $job = QueueManager::reschedule($uuid, $when);

        $this->assertNotNull($job);
        $this->assertSame(0, (int) Redis::connection()->llen('queues:default'));
        $this->assertSame(0, (int) Redis::connection()->llen('queues:default:notify'));

        $found = QueueManager::find($uuid);

        $this->assertSame(JobState::Delayed, $found->state);
        $this->assertSame($when, $found->availableAt);
    }

    public function test_it_reschedules_an_already_delayed_job(): void
    {
        $uuid = $this->dispatchJob(delay: 60);

        $when = time() + 7200;

        $this->assertNotNull(QueueManager::reschedule($uuid, $when));
        $this->assertSame($when, QueueManager::find($uuid)->availableAt);
    }

    public function test_purge_empties_pending_and_delayed_but_not_reserved(): void
    {
        $this->dispatchJob();
        $this->dispatchJob(delay: 300);
        $this->dispatchJob();

        Queue::connection('redis')->pop('default');

        $removed = QueueManager::purge('default');

        $this->assertSame(2, $removed);
        $this->assertSame(
            ['pending' => 0, 'delayed' => 0, 'reserved' => 1],
            QueueManager::counts('default'),
        );
        $this->assertSame(0, (int) Redis::connection()->llen('queues:default:notify'));
    }

    public function test_it_registers_queues_it_sees_a_job_pushed_to(): void
    {
        Queue::connection('redis')->pushOn('reports', new ExportJob);

        $this->assertContains('reports', QueueManager::queues('redis'));
    }

    public function test_it_scans_for_queues_it_never_saw_created(): void
    {
        // Straight into Redis, so no JobQueued event and nothing in the
        // registry — the way queues look on an existing app the day this
        // package is installed. Only SCAN can find these.
        Redis::connection()->rpush('queues:legacy', ['{"uuid":"x"}']);
        Redis::connection()->zadd('queues:archive:delayed', time(), '{"uuid":"y"}');

        $queues = QueueManager::queues('redis');

        $this->assertContains('legacy', $queues);
        $this->assertContains('archive', $queues);
    }

    public function test_scanning_for_queues_can_be_turned_off(): void
    {
        Redis::connection()->rpush('queues:legacy', ['{"uuid":"x"}']);

        $this->app['config']->set('queue-manager.scan.discover_queues', false);
        $this->app->forgetInstance(QueueDiscovery::class);
        $this->app->forgetInstance(JobRepository::class);
        $this->app->forgetInstance(Manager::class);
        Facade::clearResolvedInstances();

        $this->assertNotContains('legacy', QueueManager::queues('redis'));
    }

    private function dispatchJob(string $clientId = '1', ?int $delay = null): string
    {
        $job = new ExportJob($clientId);

        if ($delay !== null) {
            $job->delay($delay);
        }

        dispatch($job);

        return $this->lastUuid($delay !== null);
    }

    private function lastUuid(bool $delayed): string
    {
        $raws = $delayed
            ? Redis::connection()->zrange('queues:default:delayed', 0, -1)
            : Redis::connection()->lrange('queues:default', 0, -1);

        $payload = json_decode((string) end($raws), true);

        return (string) $payload['uuid'];
    }
}
