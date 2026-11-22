<?php

declare(strict_types=1);

use App\Pixelforge\Webhook\WebhookSignature;
use Carbon\CarbonImmutable;

mutates(WebhookSignature::class);

/*
 * The signatures below were computed outside this code base with Python:
 * hmac.new(b'whsec_test_secret', f'{timestamp}.{body}'.encode(), hashlib.sha256).hexdigest()
 */
const SIGNED_AT = 1_762_265_009;
const SIGNED_BODY = '{"id":"evt_1","type":"generation.processing"}';
const SIGNATURE = 'e6eb605b99f55e64cb9b66ec08f91095d19435db875f1c12cec49d280acde836';

function signatureVerifier(): WebhookSignature
{
    return new WebhookSignature('whsec_test_secret');
}

function receivedAt(int $timestamp): CarbonImmutable
{
    return CarbonImmutable::createFromTimestampUTC($timestamp);
}

it('accepts a correctly signed body', function () {
    expect(signatureVerifier()->isValid(SIGNED_BODY, 't='.SIGNED_AT.',v1='.SIGNATURE, receivedAt(SIGNED_AT)))->toBeTrue();
});

it('signs the raw bytes of the body, not a decoded form of it', function () {
    $body = '{"name":"Zoë","note":"ünïcode"}';
    $signature = '4e4317af91d5285ba75bf88d3f0f3e4dec9f9e09722e1ddf2a2dab8643923061';

    expect(signatureVerifier()->isValid($body, 't='.SIGNED_AT.',v1='.$signature, receivedAt(SIGNED_AT)))->toBeTrue()
        ->and(signatureVerifier()->isValid(str_replace('Zoë', 'Zoe', $body), 't='.SIGNED_AT.',v1='.$signature, receivedAt(SIGNED_AT)))->toBeFalse();
});

it('refuses anything that was not signed with our secret over this exact body and time', function (string $body, string $header) {
    expect(signatureVerifier()->isValid($body, $header, receivedAt(SIGNED_AT)))->toBeFalse();
})->with([
    'changed body' => [SIGNED_BODY.' ', 't='.SIGNED_AT.',v1='.SIGNATURE],
    'changed timestamp' => [SIGNED_BODY, 't='.(SIGNED_AT + 1).',v1='.SIGNATURE],
    'changed signature' => [SIGNED_BODY, 't='.SIGNED_AT.',v1='.strrev(SIGNATURE)],
    'signature in capitals' => [SIGNED_BODY, 't='.SIGNED_AT.',v1='.strtoupper(SIGNATURE)],
]);

it('refuses a signature made with another secret', function () {
    $other = new WebhookSignature('another_secret');

    expect($other->isValid(SIGNED_BODY, 't='.SIGNED_AT.',v1='.SIGNATURE, receivedAt(SIGNED_AT)))->toBeFalse();
});

it('refuses a malformed header', function (string $header) {
    expect(signatureVerifier()->isValid(SIGNED_BODY, $header, receivedAt(SIGNED_AT)))->toBeFalse();
})->with([
    'empty' => [''],
    'garbage' => ['garbage'],
    'no signature' => ['t='.SIGNED_AT],
    'empty signature' => ['t='.SIGNED_AT.',v1='],
    'no timestamp' => ['v1='.SIGNATURE],
    'timestamp that is not a number' => ['t=abc,v1='.SIGNATURE],
    'negative timestamp' => ['t=-5,v1='.SIGNATURE],
    'empty timestamp' => ['t=,v1='.SIGNATURE],
    'timestamp given as a list' => ['t[]='.SIGNED_AT.',v1='.SIGNATURE],
    'signature given as a list' => ['t='.SIGNED_AT.',v1[]='.SIGNATURE],
]);

it('ignores parts of the header it does not know', function () {
    $header = 't='.SIGNED_AT.',v0=old,v1='.SIGNATURE;

    expect(signatureVerifier()->isValid(SIGNED_BODY, $header, receivedAt(SIGNED_AT)))->toBeTrue();
});

it('accepts a request up to five minutes away from now and refuses anything older or newer', function (int $secondsLater, bool $valid) {
    expect(signatureVerifier()->isValid(SIGNED_BODY, 't='.SIGNED_AT.',v1='.SIGNATURE, receivedAt(SIGNED_AT + $secondsLater)))->toBe($valid);
})->with([
    'just now' => [0, true],
    'five minutes later' => [300, true],
    'just over five minutes later' => [301, false],
    'five minutes early' => [-300, true],
    'just over five minutes early' => [-301, false],
]);

it('refuses to work without a secret', function () {
    new WebhookSignature('');
})->throws(InvalidArgumentException::class, 'not configured');
