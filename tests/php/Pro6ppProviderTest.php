<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Pro6pp\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\Pro6pp\Pro6ppProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Drives the real {@link Pro6ppProvider} against canned responses with a mocked Guzzle handler (no live calls): the
 * `authKey` QUERY parameter (never a header), the per-country `/autocomplete/{cc}` endpoint + request shapes, the NL
 * structured postcode + streetNumberAndPremise lookup and its response -> ResolvedAddress mapping (settlement -> city,
 * province -> state, lat/lng, integer streetNumber -> string), per-country parameter filtering, validate() on the
 * non-NL endpoints, capability flags, and soft-fail on a fault.
 */
class Pro6ppProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TEST_KEY = 'test-key-123';

    // The verified NL address (2132JE + 31): Antareslaan, Hoofddorp, Haarlemmermeer, Noord-Holland.
    private const NL_BODY = '{"BAGNumberId":"0394010000740000","constructionYear":1995,"country":"Netherlands",'
        . '"countryCode":"NL","district":"Beukenhorst","lat":52.29496015,"lng":4.71072848,'
        . '"municipality":"Haarlemmermeer","neighbourhood":"Beukenhorst","plotSize":0,"postalCode":"2132JE",'
        . '"premise":"","province":"Noord-Holland","purposes":["kantoorfunctie"],"settlement":"Hoofddorp",'
        . '"street":"Antareslaan","streetNumber":31,"surfaceArea":5000}';

    /**
     * @var string|false
     */
    private $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = Environment::getEnv('SS_PRO6PP_API_KEY');
        Environment::setEnv('SS_PRO6PP_API_KEY', self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_PRO6PP_API_KEY', $this->originalKey === false ? '' : $this->originalKey);
        parent::tearDown();
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, mixed> $transactions
     */
    private function provider(array $responses, array &$transactions = []): Pro6ppProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new Pro6ppProvider(['handler' => $stack]);
    }

    public function testAuthKeyIsQueryParameterNotHeader(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $provider->lookup(new AddressQuery('NL', '2132JE', '31'));

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame(self::TEST_KEY, $q['authKey'] ?? null, 'Key rides the query string');
        $this->assertSame('', $request->getHeaderLine('Authorization'), 'Key is NOT sent as a header');
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testNlStructuredLookupMapsResultAndRequestShape(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '2132 JE', '31'));

        $this->assertNotNull($address);
        $this->assertSame('Antareslaan', $address->street);
        $this->assertSame('31', $address->houseNumber, 'Integer streetNumber mapped to string');
        $this->assertSame('', $address->suffix);
        $this->assertSame('Hoofddorp', $address->city, 'settlement -> city');
        $this->assertSame('Haarlemmermeer', $address->municipality);
        $this->assertSame('Noord-Holland', $address->state, 'province -> state');
        $this->assertSame('2132JE', $address->postalCode);
        $this->assertSame('NL', $address->countryCode);
        $this->assertSame(52.29496015, $address->lat);
        $this->assertSame(4.71072848, $address->lng);
        $this->assertTrue($address->exact);
        $this->assertSame('pro6pp', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('api.pro6pp.nl', $request->getUri()->getHost());
        $this->assertSame('/v2/autocomplete/nl', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('2132JE', $q['postalCode'] ?? null, 'Postcode whitespace stripped');
        $this->assertSame('31', $q['streetNumberAndPremise'] ?? null);
        $this->assertArrayNotHasKey('settlement', $q, 'NL endpoint takes no settlement');
        $this->assertArrayNotHasKey('street', $q, 'NL endpoint takes no street');
    }

    public function testNlLookupConcatenatesSuffixAndMapsPremise(): void
    {
        $body = '{"countryCode":"NL","lat":52.3,"lng":4.8,"municipality":"Amsterdam","postalCode":"1012JS",'
            . '"premise":"A","province":"Noord-Holland","settlement":"Amsterdam","street":"Dam","streetNumber":4}';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '1012 JS', '4', 'A'));

        $this->assertNotNull($address);
        $this->assertSame('4', $address->houseNumber);
        $this->assertSame('A', $address->suffix, 'premise -> suffix');

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('4A', $q['streetNumberAndPremise'] ?? null, 'House number + suffix concatenated');
    }

    public function testNlLookupSoftMissOn404(): void
    {
        // The API answers 404 "No results found" for an unknown address; the HTTP base turns that into [].
        $provider = $this->provider([new Response(404, [], '{"description":"No results found"}')]);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '2132JE', '99')));
    }

    public function testNlLookupNeedsHouseNumberAndMakesNoRequestWithout(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '2132JE')), 'Postcode alone is not enough');
        $this->assertCount(0, $transactions, 'No house number -> no billable call');
    }

    public function testNonNlLookupReturnsNullAndMakesNoRequest(): void
    {
        // BE/DE/FR/LU autocomplete all require a street a postcode-number query cannot carry, so lookup() never calls
        // (and never bills) for them — their path is validate(), driven by a fully-typed address.
        foreach (['BE', 'DE', 'FR', 'LU'] as $code) {
            $transactions = [];
            $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

            $this->assertNull($provider->lookup(new AddressQuery($code, '1000', '1')), $code . ' is a soft miss');
            $this->assertCount(0, $transactions, $code . ' makes no HTTP call from a postcode-number query');
        }
    }

    public function testUnsupportedCountryLookupMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('ZZ', '1000', '1')));
        $this->assertCount(0, $transactions);
    }

    public function testSupportsCountryAndLookupMode(): void
    {
        $provider = $this->provider([new Response(200, [], '{}')]);

        foreach (['NL', 'BE', 'DE', 'FR', 'LU', 'AT', 'CH', 'DK'] as $code) {
            $this->assertTrue($provider->supportsCountry($code), $code . ' is covered by the Pro6PP API');
        }
        $this->assertTrue($provider->supportsCountry('nl'), 'Case-insensitive');
        $this->assertFalse($provider->supportsCountry('ZZ'));
        $this->assertFalse($provider->supportsCountry('US'), 'Pro6PP does not cover the US');
        $this->assertFalse($provider->supportsCountry(''));

        $this->assertSame(AddressLookupProvider::MODE_POSTCODE_NUMBER, $provider->lookupMode('NL'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('BE'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('FR'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('LU'));
        $this->assertSame(AddressLookupProvider::MODE_NONE, $provider->lookupMode('ZZ'));

        $this->assertNull($provider->consentCategory(), 'Functional provider, no consent gate by default');
    }

    public function testSuggestReturnsEmptyWithoutRequest(): void
    {
        // Pro6PP's /suggest endpoints are structured (per-field), not a single free-text term, so there is no clean
        // mapping onto the base's free-text suggest -> it stays empty and makes no call.
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '[]')], $transactions);

        $this->assertSame([], $provider->suggest('Antareslaan 31 Hoofddorp', 'NL'));
        $this->assertCount(0, $transactions);
    }

    public function testValidateNlConfirmsAndCanonicalises(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $result = $provider->validate(new ResolvedAddress(
            street: 'Antareslaan',
            houseNumber: '31',
            postalCode: '2132 JE',
            city: 'Hoofddorp',
            countryCode: 'NL',
        ));

        $this->assertTrue($result->valid);
        $this->assertSame(1.0, $result->score);
        $this->assertNotNull($result->canonical);
        $this->assertSame('Antareslaan', $result->canonical->street);
        $this->assertSame('Hoofddorp', $result->canonical->city);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/v2/autocomplete/nl', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('2132JE', $q['postalCode'] ?? null);
        $this->assertSame('31', $q['streetNumberAndPremise'] ?? null);
        // The NL endpoint accepts neither settlement nor street, so a validate() on an NL address drops them.
        $this->assertArrayNotHasKey('settlement', $q, 'NL endpoint rejects settlement -> filtered out');
        $this->assertArrayNotHasKey('street', $q, 'NL endpoint rejects street -> filtered out');
    }

    public function testValidateFrSendsSettlementAndStreetAndMapsRegionToState(): void
    {
        $body = '{"commune":"Paris 1er Arrondissement","country":"France","countryCode":"FR","department":"Paris",'
            . '"lat":48.8626,"lng":2.3363,"postalCode":"75001","premise":"","region":"Ile-de-France",'
            . '"settlement":"Paris","street":"Rue de Rivoli","streetNumber":1}';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $result = $provider->validate(new ResolvedAddress(
            street: 'Rue de Rivoli',
            houseNumber: '1',
            postalCode: '75001',
            city: 'Paris',
            countryCode: 'FR',
        ));

        $this->assertTrue($result->valid);
        $this->assertSame(1.0, $result->score);
        $this->assertNotNull($result->canonical);
        $this->assertSame('Rue de Rivoli', $result->canonical->street);
        $this->assertSame('Ile-de-France', $result->canonical->state, 'region -> state for FR');
        $this->assertSame('FR', $result->canonical->countryCode);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/v2/autocomplete/fr', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('75001', $q['postalCode'] ?? null);
        $this->assertSame('Paris', $q['settlement'] ?? null, 'FR endpoint requires a settlement');
        $this->assertSame('Rue de Rivoli', $q['street'] ?? null, 'FR endpoint requires a street');
        $this->assertSame('1', $q['streetNumberAndPremise'] ?? null);
    }

    public function testValidateBeSendsBoxAndMapsBoxToSuffix(): void
    {
        $body = '{"box":"B","boxPrefix":"bus","country":"Belgium","countryCode":"BE","lat":50.8467,"lng":4.3525,'
            . '"postalCode":"1000","premise":"","province":"Brussels Hoofdstedelijk Gewest","region":"Brussels",'
            . '"settlement":"Brussel","street":"Grote Markt","streetNumber":1}';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $result = $provider->validate(new ResolvedAddress(
            street: 'Grote Markt',
            houseNumber: '1',
            suffix: 'B',
            postalCode: '1000',
            city: 'Brussel',
            countryCode: 'BE',
        ));

        $this->assertTrue($result->valid);
        $this->assertNotNull($result->canonical);
        $this->assertSame('B', $result->canonical->suffix, 'Empty premise falls through to box for the suffix');
        $this->assertSame('Brussels Hoofdstedelijk Gewest', $result->canonical->state, 'province -> state for BE');

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/v2/autocomplete/be', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('B', $q['box'] ?? null, 'BE sends the suffix as box');
        $this->assertSame('Grote Markt', $q['street'] ?? null);
        $this->assertArrayNotHasKey('settlement', $q, 'BE endpoint takes no settlement -> filtered out');
    }

    public function testValidateSoftPassesOnMiss(): void
    {
        $provider = $this->provider([new Response(404, [], '{"description":"No results found"}')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '0000AA', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'A 404 miss is a soft pass, not a false invalid');
        $this->assertSame(0.0, $result->score);
        $this->assertNull($result->canonical);
    }

    public function testValidateSoftPassesOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'upstream error')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '2132JE', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'A fault is a soft pass so a flaky API never blocks checkout');
        $this->assertSame(0.0, $result->score);
    }

    public function testValidateUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $result = $provider->validate(new ResolvedAddress(postalCode: '1000', countryCode: 'US'));

        $this->assertTrue($result->valid);
        $this->assertCount(0, $transactions);
    }

    public function testValidateWithoutPostcodeMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $result = $provider->validate(new ResolvedAddress(street: 'Dam', city: 'Amsterdam', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'No postcode -> cannot validate -> soft pass, no call');
        $this->assertCount(0, $transactions);
    }

    public function testIsConfiguredReflectsKeyPresence(): void
    {
        $provider = $this->provider([new Response(200, [], '{}')]);
        $this->assertTrue($provider->isConfigured());

        Environment::setEnv('SS_PRO6PP_API_KEY', '');
        $this->assertFalse($provider->isConfigured(), 'No key -> inert');
    }

    public function testNoKeyMakesNoRequest(): void
    {
        Environment::setEnv('SS_PRO6PP_API_KEY', '');
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '2132JE', '31')));
        $this->assertCount(0, $transactions, 'An unconfigured provider never calls (and never sends an empty authKey)');
    }

    public function testKeyAndGetKeyName(): void
    {
        $provider = $this->provider([new Response(200, [], '{}')]);
        $this->assertSame('pro6pp', $provider->getKey());
        $this->assertSame('Pro6PP', $provider->getName());
    }
}
