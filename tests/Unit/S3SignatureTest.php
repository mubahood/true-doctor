<?php

namespace Tests\Unit;

use App\Support\Backups\S3Client;
use PHPUnit\Framework\TestCase;

class S3SignatureTest extends TestCase
{
    /** AWS's own worked example of deriving the signing key (Signature Version 4 documentation). */
    public function test_the_signing_key_matches_the_aws_example(): void
    {
        $key = S3Client::signingKey('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830', 'us-east-1', 'iam');

        $this->assertSame('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex($key));
    }

    /** AWS's S3 example "GET Object" (s3-sigv4 header-based auth documentation). */
    public function test_a_get_object_signature_matches_the_aws_example(): void
    {
        $client = new S3Client('https://examplebucket.s3.amazonaws.com', 'us-east-1', 'examplebucket', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');

        $headers = $client->sign('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', [], ['range' => 'bytes=0-9'], 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', '20130524T000000Z');

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization'],
        );
    }
}
