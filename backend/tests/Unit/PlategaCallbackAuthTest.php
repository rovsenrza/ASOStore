<?php

use App\Services\TelegramStore\Payments\PlategaClient;

/*
| A Platega callback carries the merchant ID and secret of the checkout it belongs to;
| PlategaClient::authentic() decides which checkout (if any) that is.
*/

beforeEach(function () {
    config([
        'services.platega.merchant_id' => 'bot-merchant',
        'services.platega.secret' => 'bot-secret',
        'services.platega.site_merchant_id' => 'site-merchant',
        'services.platega.site_secret' => 'site-secret',
    ]);
});

it('names the checkout whose merchant ID and secret both match', function () {
    $client = new PlategaClient;

    expect($client->authentic('bot-merchant', 'bot-secret'))->toBe(PlategaClient::BOT)
        ->and($client->authentic('site-merchant', 'site-secret'))->toBe(PlategaClient::SITE)
        // Stray whitespace from the header does not matter.
        ->and($client->authentic(' bot-merchant ', "bot-secret\n"))->toBe(PlategaClient::BOT);
});

it('refuses a wrong, mixed, partial or missing pair', function (?string $merchant, ?string $secret) {
    expect((new PlategaClient)->authentic($merchant, $secret))->toBeNull();
})->with([
    'wrong secret' => ['bot-merchant', 'nope'],
    'secret of the other checkout' => ['bot-merchant', 'site-secret'],
    'secret prefix' => ['bot-merchant', 'bot-'],
    'empty secret' => ['bot-merchant', ''],
    'no merchant' => [null, 'bot-secret'],
    'no secret' => ['bot-merchant', null],
]);

it('only accepts the checkout the callback URL names', function () {
    $client = new PlategaClient;

    expect($client->authentic('site-merchant', 'site-secret', PlategaClient::BOT))->toBeNull()
        ->and($client->authentic('site-merchant', 'site-secret', PlategaClient::SITE))->toBe(PlategaClient::SITE);
});

it('accepts nothing while Platega is not configured', function () {
    config(['services.platega.merchant_id' => null, 'services.platega.secret' => null]);

    expect((new PlategaClient)->authentic('', ''))->toBeNull()
        ->and((new PlategaClient)->authentic('site-merchant', 'site-secret'))->toBeNull();
});

it('lets the website use the bot checkout until its own pair is set', function () {
    config(['services.platega.site_merchant_id' => null]);

    expect(PlategaClient::separateSite())->toBeFalse()
        ->and((new PlategaClient)->authentic('bot-merchant', 'bot-secret', PlategaClient::SITE))->toBe(PlategaClient::SITE)
        ->and((new PlategaClient)->authentic('site-merchant', 'site-secret'))->toBeNull();
});
