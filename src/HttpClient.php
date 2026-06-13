<?php

declare(strict_types=1);

final class HttpClient
{
    /**
     * @return array{status:int, body:string}
     */
    public function get(string $url): array
    {
        return $this->request('GET', $url);
    }

    /**
     * @return array{status:int, body:string}
     */
    public function postForm(string $url, array $formData): array
    {
        return $this->request(
            'POST',
            $url,
            http_build_query($formData, '', '&', PHP_QUERY_RFC3986),
            ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']
        );
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $body, $headers);
        }

        return $this->streamRequest($method, $url, $body, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    private function curlRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $defaultHeaders = [
            'Accept-Language: en-US,en;q=0.9',
            'User-Agent: Mozilla/5.0 KF2_WSMC/3.0',
        ];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $responseBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('HTTP request failed with status %d: %s', $statusCode, $url));
        }

        return ['status' => $statusCode, 'body' => $responseBody];
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    private function streamRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $defaultHeaders = [
            'Accept-Language: en-US,en;q=0.9',
            'User-Agent: Mozilla/5.0 KF2_WSMC/3.0',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", array_merge($defaultHeaders, $headers)),
                'content' => $body ?? '',
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed via stream context.');
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                break;
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('HTTP request failed with status %d: %s', $statusCode, $url));
        }

        return ['status' => $statusCode, 'body' => $responseBody];
    }
}

