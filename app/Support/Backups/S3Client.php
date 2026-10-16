<?php

namespace App\Support\Backups;

use Illuminate\Support\Facades\Http;

/**
 * Just enough of the S3 API to keep backups off the server: put, get, list
 * and delete, signed with AWS Signature Version 4.
 *
 * Any S3-compatible store works (Amazon S3, Backblaze B2, Wasabi, Cloudflare
 * R2, MinIO) with path-style addresses. Written here rather than pulling in
 * the AWS SDK, which would add tens of megabytes to every standalone release
 * for four calls.
 */
final class S3Client
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $key,
        private readonly string $secret,
    ) {}

    public static function fromConfig(): ?self
    {
        $c = (array) config('backup.s3', []);
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret'] as $required) {
            if (blank($c[$required] ?? null)) {
                return null;
            }
        }

        return new self(rtrim((string) $c['endpoint'], '/'), (string) $c['region'], (string) $c['bucket'], (string) $c['key'], (string) $c['secret']);
    }

    public function put(string $object, string $file): void
    {
        $body = (string) file_get_contents($file);
        $response = $this->send('PUT', $object, [], $body, ['content-type' => 'application/octet-stream']);
        if (! $response->successful()) {
            throw new \RuntimeException("The backup store refused the upload ({$response->status()}).");
        }
    }

    public function get(string $object, string $file): void
    {
        $response = $this->send('GET', $object);
        if (! $response->successful()) {
            throw new \RuntimeException("The backup store did not return {$object} ({$response->status()}).");
        }
        file_put_contents($file, $response->body());
    }

    public function delete(string $object): void
    {
        $this->send('DELETE', $object);
    }

    /** @return list<array{key:string,size:int,modified:string}> every object under $prefix, oldest key first */
    public function list(string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token !== null ? ['continuation-token' => $token] : []);
            $response = $this->send('GET', '', $query);
            if (! $response->successful()) {
                throw new \RuntimeException("The backup store could not list {$prefix} ({$response->status()}).");
            }
            $xml = simplexml_load_string($response->body());
            if ($xml === false) {
                throw new \RuntimeException('The backup store answered with something that is not a list.');
            }
            foreach ($xml->Contents ?? [] as $item) {
                $out[] = ['key' => (string) $item->Key, 'size' => (int) $item->Size, 'modified' => (string) $item->LastModified];
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null);

        usort($out, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $out;
    }

    /**
     * @param  array<string,string>  $query
     * @param  array<string,string>  $headers
     */
    private function send(string $method, string $object, array $query = [], string $body = '', array $headers = []): \Illuminate\Http\Client\Response
    {
        $path = '/'.$this->bucket.($object === '' ? '/' : '/'.implode('/', array_map('rawurlencode', explode('/', $object))));
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST).(parse_url($this->endpoint, PHP_URL_PORT) ? ':'.parse_url($this->endpoint, PHP_URL_PORT) : '');
        $signed = $this->sign($method, $host, $path, $query, $headers, hash('sha256', $body), gmdate('Ymd\THis\Z'));

        $url = $this->endpoint.$path.($query === [] ? '' : '?'.self::canonicalQuery($query));

        return Http::withHeaders($signed)->timeout(300)->withBody($body, $headers['content-type'] ?? 'application/octet-stream')->send($method, $url);
    }

    /**
     * The headers that sign a request (AWS Signature Version 4).
     *
     * @param  array<string,string>  $query
     * @param  array<string,string>  $headers
     * @return array<string,string>
     */
    public function sign(string $method, string $host, string $path, array $query, array $headers, string $payloadHash, string $amzDate): array
    {
        $date = substr($amzDate, 0, 8);
        $headers = array_change_key_case($headers + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate]);
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim((string) $value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonical = implode("\n", [$method, $path, self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $signature = hash_hmac('sha256', $toSign, self::signingKey($this->secret, $date, $this->region, 's3'));

        unset($headers['host']);

        return $headers + [
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    public static function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);

        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /** @param array<string,string> $query */
    private static function canonicalQuery(array $query): string
    {
        ksort($query);

        return implode('&', array_map(fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v), array_keys($query), $query));
    }
}
