<?php

declare(strict_types=1);

namespace AlveBy\QueueManager;

use AlveBy\QueueManager\Console\CancelJobCommand;
use AlveBy\QueueManager\Console\DeleteJobCommand;
use AlveBy\QueueManager\Console\ListJobsCommand;
use AlveBy\QueueManager\Console\PausedQueuesCommand;
use AlveBy\QueueManager\Console\PauseQueueCommand;
use AlveBy\QueueManager\Console\PruneCommand;
use AlveBy\QueueManager\Console\PurgeQueueCommand;
use AlveBy\QueueManager\Console\ResumeQueueCommand;
use AlveBy\QueueManager\Console\ShowJobCommand;
use AlveBy\QueueManager\Console\StatsCommand;
use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Contracts\JobRepository;
use AlveBy\QueueManager\Contracts\PauseStore;
use AlveBy\QueueManager\Events\CancelledJobSkipped;
use AlveBy\QueueManager\Listeners\MaintainJobIndex;
use AlveBy\QueueManager\Listeners\SkipCancelledJobs;
use AlveBy\QueueManager\Queue\CompositePauseStore;
use AlveBy\QueueManager\Queue\Horizon\PausableHorizonRedisConnector;
use AlveBy\QueueManager\Queue\NativePauseBridge;
use AlveBy\QueueManager\Queue\PausableRedisConnector;
use AlveBy\QueueManager\Redis\ConnectionRegistry;
use AlveBy\QueueManager\Redis\NullJobIndex;
use AlveBy\QueueManager\Redis\QueueDiscovery;
use AlveBy\QueueManager\Redis\RedisCancellationStore;
use AlveBy\QueueManager\Redis\RedisJobIndex;
use AlveBy\QueueManager\Redis\RedisJobRepository;
use AlveBy\QueueManager\Redis\RedisPauseStore;
use AlveBy\QueueManager\Redis\StateConnection;
use AlveBy\QueueManager\Support\Keys;
use AlveBy\QueueManager\Support\TagResolver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\ServiceProvider;

class QueueManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-manager.php', 'queue-manager');

        $this->registerCore();
        $this->registerStores();
        $this->registerConnector();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/queue-manager.php' => $this->app->configPath('queue-manager.php'),
            ], 'queue-manager-config');

            $this->commands([
                CancelJobCommand::class,
                DeleteJobCommand::class,
                ListJobsCommand::class,
                PauseQueueCommand::class,
                PausedQueuesCommand::class,
                PruneCommand::class,
                PurgeQueueCommand::class,
                ResumeQueueCommand::class,
                ShowJobCommand::class,
                StatsCommand::class,
            ]);
        }

        $this->registerListeners();
        $this->registerAboutSection();
    }

    private function registerCore(): void
    {
        $this->app->singleton(Keys::class, static fn ($app) => new Keys(
            (string) $app->make(Config::class)->get('queue-manager.prefix', 'lqm'),
        ));

        $this->app->singleton(ConnectionRegistry::class, static fn ($app) => new ConnectionRegistry(
            $app->make(Config::class),
            $app->make(RedisFactory::class),
        ));

        $this->app->singleton(StateConnection::class, static fn ($app) => new StateConnection(
            $app->make(RedisFactory::class),
            $app->make(ConnectionRegistry::class),
            $app->make(Config::class)->get('queue-manager.store_connection'),
        ));

        $this->app->singleton(TagResolver::class, static fn ($app) => new TagResolver(
            (array) $app->make(Config::class)->get('queue-manager.index.auto_tags', ['class', 'queue', 'connection']),
        ));

        $this->app->singleton(QueueDiscovery::class, static fn ($app) => new QueueDiscovery(
            $app->make(JobIndex::class),
            $app->make(Keys::class),
            (bool) $app->make(Config::class)->get('queue-manager.scan.discover_queues', true),
            (array) $app->make(Config::class)->get('queue-manager.queues', []),
        ));

        $this->app->singleton(JobRepository::class, static fn ($app) => new RedisJobRepository(
            $app->make(ConnectionRegistry::class),
            $app->make(Keys::class),
            $app->make(QueueDiscovery::class),
            (int) $app->make(Config::class)->get('queue-manager.scan.chunk', 1000),
        ));

        $this->app->singleton(Manager::class, static fn ($app) => new Manager(
            $app->make(JobRepository::class),
            $app->make(PauseStore::class),
            $app->make(CancellationStore::class),
            $app->make(JobIndex::class),
            $app->make(ConnectionRegistry::class),
            $app->make('events'),
            (bool) $app->make(Config::class)->get('queue-manager.cancellation.delete_from_queue', true),
        ));

        $this->app->alias(Manager::class, 'queue-manager');
    }

    private function registerStores(): void
    {
        $this->app->singleton(JobIndex::class, static function ($app) {
            $config = $app->make(Config::class);

            if (! $config->get('queue-manager.index.enabled', true)) {
                return new NullJobIndex;
            }

            return new RedisJobIndex(
                $app->make(StateConnection::class),
                $app->make(Keys::class),
                (int) $config->get('queue-manager.index.ttl', 604800),
                (int) $config->get('queue-manager.index.completed_ttl', 300),
                (int) $config->get('queue-manager.index.failed_ttl', 86400),
            );
        });

        $this->app->singleton(CancellationStore::class, static fn ($app) => new RedisCancellationStore(
            $app->make(StateConnection::class),
            $app->make(Keys::class),
            (int) $app->make(Config::class)->get('queue-manager.cancellation.ttl', 86400),
        ));

        $this->app->singleton(NativePauseBridge::class, static fn ($app) => new NativePauseBridge($app));

        // The concrete store is what the pausable connector reads on every
        // poll, so it is bound separately from the contract.
        $this->app->singleton(RedisPauseStore::class, static fn ($app) => new RedisPauseStore(
            $app->make(StateConnection::class),
            $app->make(ConnectionRegistry::class),
            $app->make(Keys::class),
            (int) $app->make(Config::class)->get('queue-manager.pause.cache_ttl', 0),
        ));

        $this->app->singleton(PauseStore::class, static function ($app) {
            $store = $app->make(RedisPauseStore::class);

            if (! $app->make(Config::class)->get('queue-manager.pause.native', true)) {
                return $store;
            }

            $bridge = $app->make(NativePauseBridge::class);

            if (! $bridge->supported()) {
                return $store;
            }

            return new CompositePauseStore($store, $bridge, $app->make(ConnectionRegistry::class));
        });
    }

    /**
     * Swap the redis connector for one that honours pause flags.
     *
     * Registered twice on purpose: `resolving` covers the normal case, and
     * the booted hook re-applies it in case another provider (Horizon) also
     * swapped the connector after us.
     */
    private function registerConnector(): void
    {
        if (! $this->app->make(Config::class)->get('queue-manager.pause.enabled', true)) {
            return;
        }

        $register = function ($manager): void {
            $manager->addConnector('redis', fn () => $this->makeConnector());
        };

        $this->app->resolving('queue', $register);

        $this->app->booted(function () use ($register): void {
            if ($this->app->resolved('queue')) {
                $register($this->app->make('queue'));
            }
        });
    }

    private function makeConnector(): object
    {
        $redis = $this->app->make(RedisFactory::class);

        // Horizon subclasses RedisQueue, so pausing has to subclass Horizon's
        // version when it is installed rather than the framework's.
        if (class_exists(\Laravel\Horizon\Connectors\RedisConnector::class)) {
            return new PausableHorizonRedisConnector($redis);
        }

        return new PausableRedisConnector($redis);
    }

    private function registerListeners(): void
    {
        $config = $this->app->make(Config::class);
        $events = $this->app->make('events');

        if ($config->get('queue-manager.cancellation.enabled', true)
            && $config->get('queue-manager.cancellation.strategy', 'worker') === 'worker') {
            $this->app->singleton(SkipCancelledJobs::class, static fn ($app) => new SkipCancelledJobs(
                $app->make(CancellationStore::class),
                $app->make('events'),
                (bool) $app->make(Config::class)->get('queue-manager.cancellation.record_batch_progress', true),
            ));

            $events->listen(JobProcessing::class, [SkipCancelledJobs::class, 'handle']);
        }

        if (! $config->get('queue-manager.index.enabled', true)) {
            return;
        }

        $this->app->singleton(MaintainJobIndex::class, static fn ($app) => new MaintainJobIndex(
            $app->make(JobIndex::class),
            $app->make(TagResolver::class),
            $app->make(ConnectionRegistry::class),
        ));

        $events->listen(JobQueued::class, [MaintainJobIndex::class, 'handleQueued']);
        $events->listen(JobProcessed::class, [MaintainJobIndex::class, 'handleProcessed']);
        $events->listen(JobFailed::class, [MaintainJobIndex::class, 'handleFailed']);
        $events->listen(CancelledJobSkipped::class, [MaintainJobIndex::class, 'handleCancelled']);
    }

    private function registerAboutSection(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Queue Manager', fn (): array => [
            'Managed connections' => implode(', ', $this->app->make(ConnectionRegistry::class)->names()) ?: 'none',
            'Pausing' => $this->pauseModeLabel(),
            'Cancellation' => (string) $this->app->make(Config::class)->get('queue-manager.cancellation.strategy', 'worker'),
            'Job index' => $this->app->make(JobIndex::class)->enabled() ? 'enabled' : 'disabled',
        ]);
    }

    private function pauseModeLabel(): string
    {
        $config = $this->app->make(Config::class);

        if (! $config->get('queue-manager.pause.enabled', true)) {
            return 'disabled';
        }

        return $this->app->make(NativePauseBridge::class)->supported()
            ? 'connector + native'
            : 'connector';
    }
}
