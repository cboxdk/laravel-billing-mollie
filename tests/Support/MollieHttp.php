<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mollie\Api\MollieApiClient;
use Psr\Http\Message\RequestInterface;

/**
 * A real {@see MollieApiClient} whose HTTP calls are answered from a queue, so a test drives the
 * SDK end to end (request building, hydration, error mapping) without the network. The answers
 * are Mollie's documented example responses (tests/Fixtures/api, copied from the API reference),
 * and every request the SDK sent is kept for assertions.
 */
final class MollieHttp
{
    /** @var list<array{request: RequestInterface}> */
    public array $history = [];

    public readonly MollieApiClient $client;

    private readonly MockHandler $mock;

    public function __construct()
    {
        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->client = new MollieApiClient(new Client(['handler' => $stack]));
        $this->client->setApiKey('test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM');
    }

    /** A documented example response, decoded so a test can adjust it. */
    public static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/api/'.$name.'.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<array-key, mixed>  $body */
    public function respond(array $body, int $status = 200): self
    {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/hal+json'], (string) json_encode($body)));

        return $this;
    }

    /** Mollie's error shape (application/hal+json with status, title, detail). */
    public function error(int $status, string $title, string $detail): self
    {
        return $this->respond(['status' => $status, 'title' => $title, 'detail' => $detail, '_links' => ['documentation' => ['href' => 'https://docs.mollie.com/overview/handling-errors', 'type' => 'text/html']]], $status);
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /** @return array<string, mixed> */
    public function body(int $index): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
