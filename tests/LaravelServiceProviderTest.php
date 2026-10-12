<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Couryo;
use Couryo\Laravel\CouryoServiceProvider;
use Couryo\Laravel\CouryoTransport;
use PHPUnit\Framework\TestCase;

/** Runs only when illuminate/container, illuminate/config and illuminate/support are installed. */
final class LaravelServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Illuminate\Container\Container::class) || !class_exists(\Illuminate\Config\Repository::class) || !class_exists(\Illuminate\Support\ServiceProvider::class)) {
            $this->markTestSkipped('illuminate packages are not installed');
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: \Illuminate\Container\Container, 1: object}
     */
    private function app(array $config): array
    {
        $app = new \Illuminate\Container\Container();
        $app->instance('config', new \Illuminate\Config\Repository($config));
        $mail = new class () {
            /** @var array<string, \Closure> */
            public array $drivers = [];

            public function extend(string $driver, \Closure $factory): self
            {
                $this->drivers[$driver] = $factory;

                return $this;
            }
        };
        $app->instance('mail.manager', $mail);

        $provider = new CouryoServiceProvider($app);
        $provider->register();
        $provider->boot();

        return [$app, $mail];
    }

    public function testRegistersTheClientAndTheMailTransport(): void
    {
        [$app, $mail] = $this->app(['couryo' => ['api_key' => 'ck_test_cfg', 'base_url' => 'https://api.couryo.com/v1/']]);

        $couryo = $app->make(Couryo::class);
        $this->assertInstanceOf(Couryo::class, $couryo);
        $this->assertSame($couryo, $app->make('couryo'));
        $this->assertSame('https://api.couryo.com/v1', $couryo->http->baseUrl);
        // Defaults from config/couryo.php are merged in.
        $this->assertSame('pt-BR', $app['config']->get('couryo.language'));

        $this->assertArrayHasKey('couryo', $mail->drivers);
        $transport = ($mail->drivers['couryo'])(['transport' => 'couryo']);
        $this->assertInstanceOf(CouryoTransport::class, $transport);
        $this->assertSame('couryo', (string) $transport);
    }

    public function testFallsBackToServicesCouryoKey(): void
    {
        $previous = getenv('COURYO_API_KEY');
        putenv('COURYO_API_KEY');
        try {
            [$app] = $this->app(['couryo' => ['api_key' => null], 'services' => ['couryo' => ['key' => 'ck_test_services']]]);
            $this->assertInstanceOf(Couryo::class, $app->make(Couryo::class));
        } finally {
            putenv($previous === false ? 'COURYO_API_KEY' : "COURYO_API_KEY={$previous}");
        }

        $this->assertSame(['api_key' => 'ck_test_services'], CouryoServiceProvider::clientOptions(['api_key' => null], ['key' => 'ck_test_services']));
        $this->assertSame(['api_key' => 'ck_test_mailer', 'timeout' => 10], CouryoServiceProvider::clientOptions(['api_key' => 'ck_test_cfg', 'key' => 'ck_test_mailer', 'timeout' => 10], []));
    }
}
