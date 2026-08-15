<?php

namespace Spatie\WebhookServer\Tests;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in('.');

expect()->extend('toHaveBeenMade', function () {
    /** @var array $expectedRequests */
    $expectedRequests = $this->value;

    Http::assertSentCount(count($expectedRequests));

    $recordedRequests = Http::recorded()->map(function ($recording) {
        return $recording[0];
    });

    foreach ($expectedRequests as $index => $expectedRequest) {
        /** @var Request $actualRequest */
        $actualRequest = $recordedRequests[$index];
        $expectedRequestOptions = $expectedRequest['options'];

        $actualRequestMethod = $actualRequest->method();
        /**
         * We need to strip all query params here,
         * otherwise the url assertion below will fail for
         * GET requests with query params set.
         * */
        $actualRequestUrl = str($actualRequest->url())->before("?")->toString();
        $actualRequestHeaders = collect($actualRequest->headers())
            ->mapWithKeys(function ($header, $key) {
                /**
                 * Header values as stored as array elements
                 * for some reason, so we have to pull them out here.
                 * */
                return [$key => $header[0]];
            })
            ->toArray();

        /**
         * We need to unset Content-Length because it
         * will vary based on request body. This is expected, but
         * will make some assertions fail.
         * */
        unset(
            $actualRequestHeaders['Content-Length'],
            $expectedRequestOptions['headers']['Content-Length']
        );

        expect($actualRequestMethod)->toBe(strtoupper($expectedRequest['method']))
            ->and($actualRequestUrl)->toBe($expectedRequest['url'])
            ->and($actualRequestHeaders)->toEqual($expectedRequestOptions['headers']);

        if ($actualRequestMethod === 'GET') {
            $actualRequestQueryParams = uri($actualRequest->url())->query()->toArray();
            expect($actualRequestQueryParams)->toBe($expectedRequestOptions['query']);
        } else {
            expect($actualRequest->body())->toBe($expectedRequestOptions['body']);
        }
    }
});


expect()->extend('toMatchRequestOptions', function (array $expectedRequest) {
    /** @var array $actualOptions */
    $actualOptions = $this->value;
    $expectedOptions = $expectedRequest['options'];

    if (isset($actualOptions['proxy'])) {
        expect($expectedOptions['proxy'])->toBe($actualOptions['proxy']);
    }

    if (isset($actualOptions['verify'])) {
        expect($expectedOptions['verify'])->toBe($actualOptions['verify']);
    }

    if (isset($actualOptions['timeout'])) {
        expect($expectedOptions['timeout'])->toBe($actualOptions['timeout']);
    }

    if (isset($actualOptions['on_stats'])) {
        expect($expectedOptions['on_stats'])->toBeInstanceOf(Closure::class);
    }

    if (isset($actualOptions['cert'])) {
        expect($expectedOptions['cert'])->toBe($actualOptions['cert']);
    }

    if (isset($actualOptions['ssl_key'])) {
        expect($expectedOptions['ssl_key'])->toBe($actualOptions['ssl_key']);
    }
});
