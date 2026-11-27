<?php

namespace Cinderwatch\WordPress\Http;

use Cinderwatch\Config\CinderConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WordPressHttpClient implements ClientInterface
{
    private Psr17Factory $factory;

    public function __construct(
        private readonly CinderConfig $config,
    ) {
        $this->factory = new Psr17Factory;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $args = [
            'method' => $request->getMethod(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
            'timeout' => $this->config->timeoutSeconds,
            'redirection' => 0,
            'blocking' => true,
            'sslverify' => apply_filters('cinder_sslverify', $this->shouldVerifySsl()),
        ];

        $response = wp_remote_request((string) $request->getUri(), $args);

        if (is_wp_error($response)) {
            return $this->factory->createResponse(503, 'Service Unavailable')
                ->withBody($this->factory->createStream($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $psrResponse = $this->factory->createResponse($status === 0 ? 503 : $status)
            ->withBody($this->factory->createStream($body));

        /** @var array<string, string|string[]> $responseHeaders */
        $responseHeaders = wp_remote_retrieve_headers($response);

        foreach ($responseHeaders as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $psrResponse = $psrResponse->withAddedHeader((string) $name, (string) $item);
                }

                continue;
            }

            $psrResponse = $psrResponse->withHeader((string) $name, (string) $value);
        }

        return $psrResponse;
    }

    private function shouldVerifySsl(): bool
    {
        if (defined('CINDER_SSL_VERIFY')) {
            return (bool) constant('CINDER_SSL_VERIFY');
        }

        return wp_get_environment_type() !== 'local';
    }
}
