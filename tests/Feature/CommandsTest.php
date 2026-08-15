<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Feature;

use AlveBy\QueueManager\Facades\QueueManager;
use AlveBy\QueueManager\Tests\Concerns\InteractsWithRedis;
use AlveBy\QueueManager\Tests\Fixtures\ExportJob;
use AlveBy\QueueManager\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

class CommandsTest extends TestCase
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

    public function test_stats_lists_queues(): void
    {
        dispatch(new ExportJob);

        $this->artisan('queue-manager:stats')
            ->expectsOutputToContain('default')
            ->assertSuccessful();
    }

    public function test_jobs_lists_what_is_queued(): void
    {
        $uuid = $this->dispatchJob();

        $this->artisan('queue-manager:jobs')
            ->expectsOutputToContain($uuid)
            ->assertSuccessful();
    }

    public function test_show_reports_an_unknown_uuid(): void
    {
        $this->artisan('queue-manager:show', ['uuid' => 'nope'])->assertFailed();
    }

    public function test_show_prints_a_job(): void
    {
        $uuid = $this->dispatchJob();

        $this->artisan('queue-manager:show', ['uuid' => $uuid])
            ->expectsOutputToContain(ExportJob::class)
            ->assertSuccessful();
    }

    public function test_cancel_removes_a_queued_job(): void
    {
        $uuid = $this->dispatchJob();

        $this->artisan('queue-manager:cancel', ['uuid' => [$uuid]])->assertSuccessful();

        $this->assertSame(0, QueueManager::counts('default')['pending']);
        $this->assertTrue(QueueManager::isCancelled($uuid));
    }

    public function test_delete_fails_for_an_unknown_uuid(): void
    {
        $this->artisan('queue-manager:delete', ['uuid' => ['nope']])->assertFailed();
    }

    public function test_pause_and_resume_round_trip(): void
    {
        $this->artisan('queue-manager:pause', ['queue' => 'default', '--for' => '10 minutes'])
            ->assertSuccessful();

        $this->assertTrue(QueueManager::isPaused('default'));

        $this->artisan('queue-manager:paused')
            ->expectsOutputToContain('default')
            ->assertSuccessful();

        $this->artisan('queue-manager:resume', ['queue' => 'default'])->assertSuccessful();

        $this->assertFalse(QueueManager::isPaused('default'));
    }

    public function test_resume_needs_a_queue_or_all(): void
    {
        $this->artisan('queue-manager:resume')->assertFailed();
    }

    public function test_purge_empties_a_queue(): void
    {
        $this->dispatchJob();
        $this->dispatchJob();

        $this->artisan('queue-manager:purge', ['queue' => 'default', '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, QueueManager::counts('default')['pending']);
    }

    public function test_prune_runs(): void
    {
        $this->artisan('queue-manager:prune')->assertSuccessful();
    }

    private function dispatchJob(): string
    {
        dispatch(new ExportJob);

        $raws = Redis::connection()->lrange('queues:default', 0, -1);

        return (string) json_decode((string) end($raws), true)['uuid'];
    }
}
