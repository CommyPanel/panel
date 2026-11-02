<?php

namespace App\Backups\Remote;

/**
 * AWS Signature Version 4 for S3 requests, which every S3-compatible service
 * (MinIO, Cloudflare R2, Hetzner, Backblaze, Wasabi) accepts. Only what the
 * backup upload needs: it signs the headers, never the body (the caller passes
 * the body's SHA-256).
 */
final class SigV4
{
    public function __construct(
        private readonly string $accessKey,
        #[\SensitiveParameter] private readonly string $secretKey,
        private readonly string $region,
    ) {}

    /**
     * The value of the Authorization header.
     *
     * @param  array<string, string>  $headers  every header that is signed, including host, x-amz-date and x-amz-content-sha256
     * @param  array<string, string>  $query
     */
    public function authorization(string $method, string $path, array $query, array $headers, string $payloadHash, string $amzDate): string
    {
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/{$this->region}/s3/aws4_request";

        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($lower);

        $canonicalHeaders = '';
        foreach ($lower as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }
        $signedHeaders = implode(';', array_keys($lower));

        $canonical = implode("\n", [
            strtoupper($method),
            $this->encodePath($path),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true), true), true), true);

        $signature = hash_hmac('sha256', $toSign, $key);

        return "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /**
     * @param  array<string, string>  $query
     */
    private function canonicalQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }

        ksort($pairs);

        return implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($pairs), $pairs));
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
