<?php

namespace Spatie\WebhookServer\Tests\TestClasses;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class TestClient
{
    protected array $requests = [];

    public function fake(): void
    {

    }

    public function request(string $method, $url = '', array $options = []): ResponseInterface
    {
        $this->requests[] = compact('method', 'url', 'options');

        if ($this->throwRequestException) {
            throw new RequestException(
                'Request failed exception',
                new Request($method, $url),
                new Response(500)
            );
        }

        if ($this->throwConnectionException) {
            throw new ConnectException(
                'Request timeout',
                new Request($method, $url),
            );
        }

        return new Response($this->useResponseCode);
    }

    public function assertRequestsMade(array $expectedRequests)
    {
    }

    public function letEveryRequestFail()
    {
        $this->useResponseCode = 500;
    }

    public function throwRequestException()
    {
        $this->throwRequestException = true;
    }

    public function throwConnectionException()
    {
        $this->throwConnectionException = true;
    }
}
