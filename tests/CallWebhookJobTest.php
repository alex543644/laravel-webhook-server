<?php

namespace Spatie\WebhookServer\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;
use Spatie\TestTime\TestTime;
use Spatie\WebhookServer\BackoffStrategy\ExponentialBackoffStrategy;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\WebhookCall;

beforeEach(function () {
    /**
     * Do not call Http::fake() here,
     * otherwise faked Http responses with
     * error status codes will be overridden.
     *
     * For example, "Http::fake(['*' => Http::response(status: 500)])"
     * will be overridden by the first Http::fake() executed here.
     * */
    Event::fake();
});

it('can make a webhook call', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()->dispatch();

    artisan('queue:work --once');
    $request = $this->createBaseRequest();

    expect([$request])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($request);
});

it('can make a synchronous webhook call', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()->dispatchSync();

    $request = $this->createBaseRequest();

    expect([$request])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($request);
});

it('can use a different HTTP verb', function () {
    Http::fake();

    $this->createBaseWebhook()
        ->useHttpVerb('put')
        ->dispatch();

    artisan('queue:work --once');

    expect([
        $this->createBaseRequest(['method' => 'put']),
    ])->toHaveBeenMade();
});

it('uses query option when http verb is get', function () {
    Http::fake();

    $this->createBaseWebhook()
        ->useHttpVerb('get')
        ->dispatch();

    artisan('queue:work --once');

    expect([
        $this->createBaseGetRequest(),
    ])->toHaveBeenMade();
});

it('can add extra headers', function () {
    Http::fake();

    $extraHeaders = [
        'header1' => 'value1',
        'headers2' => 'value2',
    ];

    $this->createBaseWebhook()
        ->withHeaders($extraHeaders)
        ->dispatch();

    artisan('queue:work --once');

    expect([
        $this->createBaseRequest([
            'options' => [
                'headers' => $extraHeaders,
            ],
        ]),
    ])->toHaveBeenMade();
});

it('will not set a signature header when the request should not be signed', function () {
    Http::fake();

    $this->createBaseWebhook()
        ->doNotSign()
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    unset($baseRequest['options']['headers']['Signature']);

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade();
});

it('can disable verifying SSL', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()->doNotVerifySsl()->dispatch();

    $baseRequest = $this->createBaseRequest();
    $baseRequest['options']['verify'] = false;

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

it('will use mutual TLS without passphrases', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()
        ->mutualTls('foobar', 'barfoo')
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $baseRequest['options']['cert'] = ['foobar', null];
    $baseRequest['options']['ssl_key'] = ['barfoo', null];

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

it('will use mutual TLS with passphrases', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()
        ->mutualTls('foobar', 'barfoo', 'foobarpassword', 'barfoopassword')
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $baseRequest['options']['cert'] = ['foobar', 'foobarpassword'];
    $baseRequest['options']['ssl_key'] = ['barfoo', 'barfoopassword'];

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

it('will use mutual TLS with certificate authority', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()
        ->mutualTls('foobar', 'barfoo')
        ->verifySsl('foofoo')
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $baseRequest['options']['cert'] = ['foobar', null];
    $baseRequest['options']['ssl_key'] = ['barfoo', null];
    $baseRequest['options']['verify'] = 'foofoo';

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

it('will use a proxy', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()
        ->useProxy('https://proxy.test')
        ->dispatch();

    $baseRequest = $this->createBaseRequest();
    $baseRequest['options']['proxy'] = 'https://proxy.test';

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

it('will use a proxy array', function () {
    $interceptedOptions = [];
    Http::fake([
        '*' => function ($_, array $options) use (&$interceptedOptions) {
            $interceptedOptions = $options;

            return Http::response();
        },
    ]);

    $this->createBaseWebhook()
        ->useProxy([
            'http' => 'http://proxy.test',
            'https' => 'https://proxy.test',
        ])
        ->dispatch();

    $baseRequest = $this->createBaseRequest();
    $baseRequest['options']['proxy'] = [
        'http' => 'http://proxy.test',
        'https' => 'https://proxy.test',
    ];

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade()
        ->and($interceptedOptions)->toMatchRequestOptions($baseRequest);
});

test('by default it will retry 3 times with the exponential backoff strategy', function () {
    Http::fake([
        '*' => Http::response(status: 500),
    ]);

    $this->createBaseWebhook()->dispatch();

    mock(ExponentialBackoffStrategy::class, function (MockInterface $mock) {
        $mock->shouldReceive('waitInSecondsAfterAttempt')->withArgs([1])->once()->andReturns(10);
        $mock->shouldReceive('waitInSecondsAfterAttempt')->withArgs([2])->once()->andReturns(100);
        $mock->shouldReceive('waitInSecondsAfterAttempt')->withArgs([3])->never();

        return $mock;
    });

    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, 1);

    TestTime::addSeconds(9);
    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, 1);

    TestTime::addSeconds(1);
    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, 2);

    TestTime::addSeconds(100);
    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, 3);
    Event::assertDispatched(FinalWebhookCallFailedEvent::class, 1);
    Http::assertSentCount(3);

    TestTime::addSeconds(1000);
    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, 3);
    Event::assertDispatched(FinalWebhookCallFailedEvent::class, 1);
    Http::assertSentCount(3);
});

it('sets the response field on request failure', function () {
    Http::fake([
        '*' => Http::response(status: 500),
    ]);

    $this->createBaseWebhook()->dispatch();

    artisan('queue:work --once');
    Event::assertDispatched(WebhookCallFailedEvent::class, function (WebhookCallFailedEvent $event) {
        $this->assertNotNull($event->response);

        return true;
    });
});

it('sets the error fields on connection failure', function () {
    Http::fake([
        '*' => Http::sequence()->pushFailedConnection(),
    ]);

    $this->createBaseWebhook()->dispatch();

    artisan('queue:work --once');

    Event::assertDispatched(WebhookCallFailedEvent::class, function (WebhookCallFailedEvent $event) {
        expect($event->errorType)->not->toBeNull()
            ->and($event->errorMessage)->not->toBeNull();

        return true;
    });
});

it('generates job failed event if an exception throws and throw exception on failure config is set', function () {
    Http::fake([
        '*' => Http::sequence()->pushFailedConnection(),
    ]);

    $this->createBaseWebhook()->maximumTries(1)->throwExceptionOnFailure()->dispatch();

    artisan('queue:work --once');

    Event::assertDispatched(JobFailed::class, function (JobFailed $event) {
        expect($event->exception)->toBeInstanceOf(ConnectionException::class);

        return true;
    });
});

it('sends raw body data if rawBody is set', function () {
    Http::fake();

    $testBody = "<xml>anotherOption</xml>";
    WebhookCall::create()
        ->url('https://example.com/webhooks')
        ->useSecret('abc')
        ->sendRawBody($testBody)
        ->doNotSign()
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $baseRequest['options']['body'] = $testBody;
    unset($baseRequest['options']['headers']['Signature']);

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade();
});


it('sends raw body data in event if rawBody is set', function () {
    Http::fake([
        '*' => Http::sequence()->pushFailedConnection(),
    ]);

    $testBody = "<xml>anotherOption</xml>";
    WebhookCall::create()
        ->url('https://example.com/webhooks')
        ->useSecret('abc')
        ->sendRawBody($testBody)
        ->doNotSign()
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $baseRequest['options']['body'] = $testBody;
    unset($baseRequest['options']['headers']['Signature']);

    artisan('queue:work --once');

    Event::assertDispatched(WebhookCallFailedEvent::class, function (WebhookCallFailedEvent $event) use ($testBody) {
        expect($event->errorType)->not->toBeNull()
            ->and($event->errorMessage)->not->toBeNull()
            ->and($event->payload)->toBe($testBody);

        return true;
    });
});

it('sets the timestamp header when using the timestamp option', function () {
    Http::fake();

    $this->createBaseWebhook()
        ->useTimestamp()
        ->dispatch();

    $baseRequest = $this->createBaseRequest();

    $timestampHeaderName = config('webhook-server.timestamp_header_name');

    $baseRequest['options']['headers'][$timestampHeaderName] = (string)TestTime::now()->getTimestamp();

    artisan('queue:work --once');

    expect([$baseRequest])->toHaveBeenMade();
});
