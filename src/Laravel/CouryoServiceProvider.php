<?php

declare(strict_types=1);

namespace Couryo\Laravel;

use Couryo\Couryo;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel integration (auto-discovered): config/couryo.php, the Couryo\Couryo singleton
 * (alias "couryo") and the "couryo" mail transport for MAIL_MAILER=couryo.
 */
class CouryoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/couryo.php', 'couryo');

        $this->app->singleton(Couryo::class, function ($app): Couryo {
            return new Couryo(self::clientOptions($app['config']->get('couryo', []), $app['config']->get('services.couryo', [])));
        });
        $this->app->alias(Couryo::class, 'couryo');
    }

    public function boot(): void
    {
        if (method_exists($this->app, 'runningInConsole') && $this->app->runningInConsole() && method_exists($this->app, 'configPath')) {
            $this->publishes([__DIR__ . '/../../config/couryo.php' => $this->app->configPath('couryo.php')], 'couryo-config');
        }

        if ($this->app->bound('mail.manager')) {
            $this->app['mail.manager']->extend('couryo', function (array $config = []): CouryoTransport {
                // A key on the mailer entry (config/mail.php) wins over the global one.
                $client = !empty($config['key']) || !empty($config['api_key'])
                    ? new Couryo(self::clientOptions(array_merge($this->app['config']->get('couryo', []), $config), []))
                    : $this->app->make(Couryo::class);

                return new CouryoTransport($client);
            });
        }
    }

    /**
     * @param array<string, mixed> $config   config('couryo'), optionally merged with the mailer entry
     * @param array<string, mixed> $services config('services.couryo')
     *
     * @return array<string, mixed>
     */
    public static function clientOptions(array $config, array $services): array
    {
        $key = $config['key'] ?? $config['api_key'] ?? null;
        if ($key === null || $key === '') {
            $key = $services['key'] ?? $services['api_key'] ?? null;
        }

        return array_filter([
            'api_key' => $key,
            'base_url' => $config['base_url'] ?? $services['base_url'] ?? null,
            'language' => $config['language'] ?? null,
            'timeout' => $config['timeout'] ?? null,
            'max_retries' => $config['max_retries'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
