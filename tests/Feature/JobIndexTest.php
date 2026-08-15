<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Feature;

use AlveBy\QueueManager\Contracts\JobIndex;
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

class JobIndexTest extends TestCase
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

    public function test_it_finds_jobs_by_a_tag_the_job_declared(): void
    {
        dispatch(new ExportJob('42'));
        dispatch(new ExportJob('99'));

        $jobs = QueueManager::search()->tag('client:42')->get();

        $this->assertCount(1, $jobs);
        $this->assertSame(ExportJob::class, $jobs->first()->name);
        $this->assertContains('client:42', $jobs->first()->tags);
    }

    public function test_it_finds_jobs_by_class(): void
    {
        dispatch(new ExportJob('42'));

        $this->assertCount(1, QueueManager::search()->forClass(ExportJob::class)->get());
    }

    public function test_multiple_tags_intersect(): void
    {
        dispatch(new ExportJob('42'));
        Queue::connection('redis')->pushOn('reports', new ExportJob('42'));

        $this->assertCount(2, QueueManager::search()->tag('client:42')->get());
        $this->assertCount(1, QueueManager::search()->tag('client:42', 'queue:reports')->get());
    }

    public function test_the_index_records_queue_and_connection(): void
    {
        Queue::connection('redis')->pushOn('reports', new ExportJob('7'));

        $job = QueueManager::search()->tag('client:7')->first();

        $this->assertSame('reports', $job->queue);
        $this->assertSame('redis', $job->connection);
        $this->assertSame(JobState::Pending, $job->state);
    }

    public function test_a_completed_job_leaves_the_tag_index(): void
    {
        dispatch(new ExportJob('42'));

        $this->assertCount(1, QueueManager::search()->tag('client:42')->get());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        $this->assertSame(['42'], ExportJob::$handled);
        $this->assertCount(0, QueueManager::search()->tag('client:42')->get());
    }

    public function test_find_falls_back_to_the_index_once_the_job_has_run(): void
    {
        dispatch(new ExportJob('42'));

        $uuid = QueueManager::search()->tag('client:42')->first()->uuid;

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        $record = QueueManager::find($uuid);

        $this->assertNotNull($record);
        $this->assertSame(JobState::Completed, $record->state);
    }

    public function test_cancelling_marks_the_index_entry(): void
    {
        dispatch(new ExportJob('42'));

        $uuid = QueueManager::search()->tag('client:42')->first()->uuid;

        QueueManager::cancel($uuid, 'nope');

        $this->assertSame(JobState::Cancelled, QueueManager::find($uuid)->state);
    }

    public function test_searching_by_tag_reports_a_clear_error_when_the_index_is_off(): void
    {
        $this->app['config']->set('queue-manager.index.enabled', false);

        foreach ([JobIndex::class, QueueDiscovery::class, JobRepository::class, Manager::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Facade::clearResolvedInstances();

        $this->expectExceptionMessage('queue-manager.index.enabled is false');

        QueueManager::search()->tag('client:42')->get();
    }
}
