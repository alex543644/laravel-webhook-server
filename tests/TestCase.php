<?php

namespace Spatie\WebhookServer\Tests;

use GuzzleHttp\TransferStats;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\WebhookServer\WebhookCall;
use Spatie\WebhookServer\WebhookServerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    public function createBaseWebhook(): WebhookCall
    {
        return WebhookCall::create()
            ->url('https://example.com/webhooks')
            ->useSecret('abc')
            ->payload(['a' => 1]);
    }

    protected function getSharedRequestHeaders(): array
    {
        return [
            'Content-Length' => '7',
            'User-Agent' => 'GuzzleHttp/7',
            'Host' => 'example.com',
            'Content-Type' => 'application/json',
            'Signature' => '1f14a62b15ba5095326d6c75c3e2e6b462dd71e1c4b7fbdac0f32309adb7be5f',
        ];
    }

    public function createBaseGetRequest(array $overrides = []): array
    {
        $headers = $this->getSharedRequestHeaders();
        /**
         * GET requests do not have a body, so this
         * header is not needed here. The Laravel Http client
         * removes it automatically either way.
         * */
        unset($headers['Content-Length']);

        $defaultProperties = [
            'method' => 'get',
            'url' => 'https://example.com/webhooks',
            'options' => [
                'timeout' => 3,
                'query' => ['a' => '1'],
                'verify' => true,
                'headers' => $headers,
                'on_stats' => function (TransferStats $stats) {
                },
            ],
        ];

        return array_replace_recursive($defaultProperties, $overrides);
    }

    /**
     * @throws JsonException
     */
    public function createBaseRequest(array $overrides = []): array
    {
        $defaultProperties = [
            'method' => 'post',
            'url' => 'https://example.com/webhooks',
            'options' => [
                'timeout' => 3,
                'body' => json_encode(['a' => 1], JSON_THROW_ON_ERROR),
                'verify' => true,
                'headers' => $this->getSharedRequestHeaders(),
                'on_stats' => function (TransferStats $stats) {
                },
            ],
        ];

        return array_replace_recursive($defaultProperties, $overrides);
    }

    protected function getPackageProviders($app): array
    {
        return [
            WebhookServerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUpDatabase(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
