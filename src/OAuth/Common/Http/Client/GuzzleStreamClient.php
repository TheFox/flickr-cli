<?php

namespace TheFox\OAuth\Common\Http\Client;

use InvalidArgumentException;
use OAuth\Common\Http\Client\AbstractClient;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Http\Uri\UriInterface;
use Guzzle\Http\Client;

class GuzzleStreamClient extends AbstractClient
{
    /**
     * @param UriInterface $endpoint
     * @param mixed $requestBody
     * @param array $extraHeaders
     * @param string $method
     * @return string
     * @throws TokenResponseException
     */
    public function retrieveResponse(
        UriInterface $endpoint,
        $requestBody,
        array $extraHeaders = [],
        $method = 'POST'
    ) {
    
        $method = strtoupper($method);

        $client = new Client();
        $headers = ['Connection' => 'close'];
        $headers = array_merge($headers, $extraHeaders);
        $response = null;

        if ($method == 'POST') {
            $request = $client->post($endpoint->getAbsoluteUri(), $headers, $requestBody);
            $response = $request->send();
        } elseif ($method == 'GET') {
            throw new InvalidArgumentException('"GET" request not implemented.');
        }

        if ($response && !$response->isSuccessful()) {
            throw new TokenResponseException('Failed to request token.');
        }

        $responseHtml = (string)$response->getBody();
        return $responseHtml;
    }
}
