<?php

namespace Spatie\WebhookServer;

use Exception;
use GuzzleHttp\TransferStats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Spatie\WebhookServer\BackoffStrategy\BackoffStrategy;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Throwable;

class CallWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?string $webhookUrl = null;

    public string $httpVerb;

    public string|array|null $proxy = null;

    public int $tries;

    public int $requestTimeout;

    public ?string $cert = null;

    public ?string $certPassphrase = null;

    public ?string $sslKey = null;

    public ?string $sslKeyPassphrase = null;

    public string $backoffStrategyClass;

    public ?string $signerClass = null;

    public array $headers = [];

    public string|bool $verifySsl;

    public bool $useTimestamp = false;

    public bool $throwExceptionOnFailure;

    /** @var string|null */
    public $queue = null;

    public array|string $payload = [];

    public array $meta = [];

    public array $tags = [];

    public string $uuid = '';

    public string $outputType = "JSON";

    protected ?Response $response = null;

    protected ?string $errorType = null;

    protected ?string $errorMessage = null;

    protected ?TransferStats $transferStats = null;

    public function handle(): void
    {
        $lastAttempt = $this->attempts() >= $this->tries;

        try {
            $client = $this->getClient();

            $httpVerb = strtolower($this->httpVerb);
            $preparedRequest = $httpVerb === 'get'
                ? $client->withQueryParameters($this->payload)
                : $client->withBody($this->generateBody());

            $this->response = $preparedRequest->{$httpVerb}($this->webhookUrl);

            //            dd($this->response->getStatusCode());
            if (! Str::startsWith($this->response->getStatusCode(), 2)) {
                throw new RuntimeException('Webhook call failed');
            }

            $this->dispatchEvent(WebhookCallSucceededEvent::class);
        } catch (Exception $exception) {
            if ($exception instanceof RequestException) {
                $this->response = $exception->response;
                $this->errorType = get_class($exception);
                $this->errorMessage = $exception->getMessage();
            }

            if ($exception instanceof ConnectionException) {
                $this->errorType = get_class($exception);
                $this->errorMessage = $exception->getMessage();
            }

            if (! $lastAttempt) {
                /** @var BackoffStrategy $backoffStrategy */
                $backoffStrategy = app($this->backoffStrategyClass);

                $waitInSeconds = $backoffStrategy->waitInSecondsAfterAttempt($this->attempts());

                $this->release($waitInSeconds);
            }

            $this->dispatchEvent(WebhookCallFailedEvent::class);

            if ($lastAttempt || $this->shouldBeRemovedFromQueue()) {
                $this->dispatchEvent(FinalWebhookCallFailedEvent::class);

                $this->throwExceptionOnFailure ? $this->fail($exception) : $this->delete();
            }
        }
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    protected function getClient(): PendingRequest
    {
        return Http::timeout($this->requestTimeout)
            ->withHeaders($this->headers)
            ->withOptions(array_merge(
                [
                    'verify' => $this->verifySsl,
                    'on_stats' => function (TransferStats $stats) {
                        $this->transferStats = $stats;
                    },
                ],
                is_null($this->proxy) ? [] : ['proxy' => $this->proxy],
                is_null($this->cert) ? [] : ['cert' => [$this->cert, $this->certPassphrase]],
                is_null($this->sslKey) ? [] : ['ssl_key' => [$this->sslKey, $this->sslKeyPassphrase]]
            ))
            ->when($this->useTimestamp, function (PendingRequest $request) {
                return $request->withHeader(
                    config('webhook-server.timestamp_header_name'),
                    now()->timestamp
                );
            });
    }

    protected function shouldBeRemovedFromQueue(): bool
    {
        return false;
    }

    private function dispatchEvent(string $eventClass): void
    {
        event(new $eventClass(
            $this->httpVerb,
            $this->webhookUrl,
            $this->payload,
            $this->headers,
            $this->meta,
            $this->tags,
            $this->attempts(),
            $this->response,
            $this->errorType,
            $this->errorMessage,
            $this->uuid,
            $this->transferStats
        ));
    }

    /**
     * @throws JsonException
     */
    private function generateBody(): string
    {
        return match ($this->outputType) {
            "RAW" => $this->payload,
            default => json_encode($this->payload, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * @throws Throwable
     */
    public function failed(Throwable $e): void
    {
        if ($this->throwExceptionOnFailure) {
            throw $e;
        }
    }
}
