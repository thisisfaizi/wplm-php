<?php

declare(strict_types=1);

namespace WPLM\Client\Tests;

use WPLM\Client\Http\Response;
use WPLM\Client\Http\Transport;

/**
 * A scripted transport for unit tests, recording every request.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,body:?string}> */
    public array $calls = [];

    /** @var callable(string,string,?string):Response */
    private $handler;

    /**
     * @param callable(string,string,?string):Response $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        return ($this->handler)($method, $url, $body);
    }
}
