<?php

use App\Backups\Remote\SigV4;

// The worked examples of "Signature Calculations for the Authorization Header" in
// the Amazon S3 documentation (GET Object with a Range header, then GET Bucket
// lifecycle and List Objects), which are public test vectors for this algorithm.
const EMPTY_BODY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

it('signs the GET Object example of the S3 documentation', function () {
    $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

    $header = $signer->authorization('GET', '/test.txt', [], [
        'Host' => 'examplebucket.s3.amazonaws.com',
        'Range' => 'bytes=0-9',
        'x-amz-content-sha256' => EMPTY_BODY,
        'x-amz-date' => '20130524T000000Z',
    ], EMPTY_BODY, '20130524T000000Z');

    expect($header)->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');
});

it('signs the List Objects example of the S3 documentation', function () {
    $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

    $header = $signer->authorization('GET', '/', ['max-keys' => '2', 'prefix' => 'J'], [
        'Host' => 'examplebucket.s3.amazonaws.com',
        'x-amz-content-sha256' => EMPTY_BODY,
        'x-amz-date' => '20130524T000000Z',
    ], EMPTY_BODY, '20130524T000000Z');

    expect($header)->toContain('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
});

it('encodes the path and the query so that odd names sign correctly', function () {
    $signer = new SigV4('AK', 'SK', 'eu-central-1');
    $plain = $signer->authorization('PUT', '/bucket/a b/c+d.gz', [], ['host' => 'x'], EMPTY_BODY, '20261103T040000Z');

    // Same request, same signature; a different path gives another one.
    expect($plain)->toBe($signer->authorization('PUT', '/bucket/a b/c+d.gz', [], ['host' => 'x'], EMPTY_BODY, '20261103T040000Z'))
        ->and($plain)->not->toBe($signer->authorization('PUT', '/bucket/a b/c+d2.gz', [], ['host' => 'x'], EMPTY_BODY, '20261103T040000Z'));
});
