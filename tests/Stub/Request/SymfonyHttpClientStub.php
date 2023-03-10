<?php

declare(strict_types=1);

namespace App\Tests\Stub\Request;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @see \App\Tests\Unit\Stub\SymfonyHttpClientStubTest
 */
class SymfonyHttpClientStub extends MockHttpClient
{
    private ResponseInterface $defaultResponse;

    /**
     * @var array<callable>
     */
    private array $matchers = [];

    /**
     * @var RequestCall[]
     */
    private array $requestCalls = [];

    public function __construct(?string $baseUri = null)
    {
        parent::__construct($this->handler(), $baseUri);

        $this->defaultResponse = new MockResponse('', ['http_code' => 404]);
    }

    private function handler(): Closure
    {
        return function ($method, $url, $options) {
            foreach ($this->matchers as $matcher) {
                if ($response = $matcher($method, $url, $options)) {
                    break;
                }
            }

            $urlParts = \parse_url($url);

            $params = [];
            if ($query = $urlParts['query'] ?? null) {
                \parse_str($query, $params);
            }

            $data = [];
            if ($body = $options['body'] ?? null) {
                \parse_str($body, $data);
            }

            $this->requestCalls[] = new RequestCall(
                $method,
                $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'],
                $params,
                $data ?: null,
            );

            return $response ?? $this->defaultResponse;
        };
    }

    /**
     * @param callable $matcher fn(string $method, string $url, array $options): bool
     */
    public function match(callable $matcher, ResponseInterface $result): self
    {
        $this->matchers[] = static fn ($method, $url, $options) => $matcher($method, $url, $options) ? $result : null;

        return $this;
    }

    private function ensureRegexp(string $regexp): string
    {
        if (\preg_match("/^([^\w\s]).+\1[a-z]*$/i", $regexp) === 0) {
            foreach (['#', '@', '/', '-', ','] as $delimiter) {
                if (!\str_contains($delimiter, $regexp)) {
                    break;
                }
            }

            $regexp = \sprintf('%2$s^%s$%2$s', $regexp, $delimiter);
        }

        if (\preg_match($regexp, '') === false) {
            throw new InvalidArgumentException(\sprintf('Invalid regexp %s.', $regexp));
        }

        return $regexp;
    }

    /**
     * @param array<string, string> $params
     */
    public function matchGet(string $url, array $params, ResponseInterface $response): self
    {
        $url = $params ? $url . '?' . \http_build_query($params) : $url;
        $urlRegexp = \addcslashes($url, '?+.*');

        return $this->matchMethodAndUrl(Request::METHOD_GET, $urlRegexp, $response);
    }

    public function matchMethodAndUrl(string $methodRegExp, string $urlRegexp, ResponseInterface $response): self
    {
        $methodRegExp = $this->ensureRegexp($methodRegExp);
        $urlRegexp = $this->ensureRegexp($urlRegexp);

        return $this->match(
            static fn ($method, $url) => \preg_match($methodRegExp, $method) && \preg_match($urlRegexp, $url),
            $response,
        );
    }

    public function getRequestCalls(): array
    {
        return $this->requestCalls;
    }
}
