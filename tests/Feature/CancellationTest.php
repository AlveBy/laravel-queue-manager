<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Feature;

use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Events\CancelledJobSkipped;
use AlveBy\QueueManager\Events\JobCancelled;
use AlveBy\QueueManager\Facades\QueueManager;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Tests\Concerns\InteractsWithRedis;
use AlveBy\QueueManager\Tests\Fixtures\ExportJob;
use AlveBy\QueueManager\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class CancellationTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRedis();

        ExportJob::$handled = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownRedis();

        parent::tearDown();
    }

    public function test_cancelling_a_pending_job_removes_it_outright(): void
    {
        $uuid = $this->dispatchJob();

        $result = QueueManager::cancel($uuid, 'user changed their mind');

        $this->assertTrue($result->wasRemoved());
        $this->assertSame(JobState::Pending, $result->state);
        $this->assertSame(0, QueueManager::counts('default')['pending']);
        $this->assertSame(0, (int) Redis::connection()->llen('queues:default:notify'));
    }

    public function test_cancelling_a_delayed_job_removes_it_outright(): void
    {
        $uuid = $this->dispatchJob(delay: 3600);

        $this->assertTrue(QueueManager::cancel($uuid)->wasRemoved());
        $this->assertSame(0, QueueManager::counts('default')['delayed']);
    }

    public function test_cancelling_leaves_a_flag_behind_even_when_the_job_is_removed(): void
    {
        $uuid = $this->dispatchJob();

        QueueManager::cancel($uuid, 'nope');

        $this->assertTrue(QueueManager::isCancelled($uuid));
    }

    public function test_a_reserved_job_is_flagged_rather_than_deleted(): void
    {
        $uuid = $this->dispatchJob();

        Queue::connection('redis')->pop('default');

        $result = QueueManager::cancel($uuid);

        $this->assertFalse($result->wasRemoved());
        $this->assertTrue($result->isDeferred());
        $this->assertSame(JobState::Reserved, $result->state);

        // Still reserved: a worker may be running it, and yanking the
        // reservation would only remove its retry safety net.
        $this->assertSame(1, QueueManager::counts('default')['reserved']);
    }

    public function test_a_worker_drops_a_cancelled_job_instead_of_running_it(): void
    {
        $uuid = $this->dispatchJob();

        // Flag it without removing it, the way a job that was already in
        // flight when it got cancelled would look to a worker.
        $this->store()->cancel($uuid, 'too late');

        $this->assertSame(1, QueueManager::counts('default')['pending']);

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        $this->assertSame([], ExportJob::$handled);
        $this->assertSame(0, QueueManager::counts('default')['pending']);
        $this->assertSame(0, QueueManager::counts('default')['reserved']);
    }

    public function test_a_worker_runs_a_job_that_was_not_cancelled(): void
    {
        $this->dispatchJob();

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        $this->assertSame(['1'], ExportJob::$handled);
    }

    public function test_uncancel_clears_the_flag(): void
    {
        $uuid = $this->dispatchJob();

        QueueManager::cancel($uuid);
        $this->assertTrue(QueueManager::isCancelled($uuid));

        $this->assertTrue(QueueManager::uncancel($uuid));
        $this->assertFalse(QueueManager::isCancelled($uuid));
    }

    public function test_it_fires_a_job_cancelled_event(): void
    {
        Event::fake([JobCancelled::class]);

        $uuid = $this->dispatchJob();

        QueueManager::cancel($uuid, 'because');

        Event::assertDispatched(
            JobCancelled::class,
            fn (JobCancelled $event): bool => $event->uuid === $uuid
                && $event->reason === 'because'
                && $event->removed,
        );
    }

    public function test_it_fires_an_event_when_a_worker_skips_a_job(): void
    {
        Event::fake([CancelledJobSkipped::class]);

        $uuid = $this->dispatchJob();

        $this->store()->cancel($uuid, 'flagged');

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        Event::assertDispatched(
            CancelledJobSkipped::class,
            fn (CancelledJobSkipped $event): bool => $event->uuid === $uuid && $event->reason === 'flagged',
        );
    }

    public function test_cancel_many_reports_per_uuid(): void
    {
        $first = $this->dispatchJob('a');
        $second = $this->dispatchJob('b');

        $results = QueueManager::cancelMany([$first, $second, 'ghost'], 'batch abort');

        $this->assertTrue($results[$first]->wasRemoved());
        $this->assertTrue($results[$second]->wasRemoved());
        $this->assertFalse($results['ghost']->wasRemoved());
        $this->assertTrue($results['ghost']->isDeferred());
    }

    private function store(): CancellationStore
    {
        return $this->app->make(CancellationStore::class);
    }

    private function dispatchJob(string $clientId = '1', ?int $delay = null): string
    {
        $job = new ExportJob($clientId);

        if ($delay !== null) {
            $job->delay($delay);
        }

        dispatch($job);

        $raws = $delay !== null
            ? Redis::connection()->zrange('queues:default:delayed', 0, -1)
            : Redis::connection()->lrange('queues:default', 0, -1);

        return (string) json_decode((string) end($raws), true)['uuid'];
    }
}
