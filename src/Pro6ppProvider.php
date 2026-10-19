<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Pro6pp;

use SilverShop\AddressLookup\Provider\AbstractHttpProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;

/**
 * Pro6PP v2 address provider — the paid satellite for {@see \SilverShop\AddressLookup\Provider\AddressLookupProvider}.
 * Host https://api.pro6pp.nl/v2, authenticated with the API key as the `authKey` QUERY parameter (NOT a header), read
 * from the environment as {@see self::$api_key_env} (default `SS_PRO6PP_API_KEY`). The key rides the query string
 * of the SERVER-SIDE request only — the base controller proxies the lookup, so the key is never exposed to the browser.
 *
 * Capabilities, exactly as the v2 `/autocomplete/{cc}` endpoints honour them (verified against the live OpenAPI spec):
 *  - **NL** → {@see self::MODE_POSTCODE_NUMBER}. `/autocomplete/nl` needs only `postalCode` (+ optional
 *    `streetNumberAndPremise`), so a postcode + house number resolves straight to a full structured address
 *    (street + settlement + municipality + province + lat/lng). This is Pro6PP's flagship capability and the one the
 *    base checkout lookup flow drives.
 *  - **BE, DE, FR, LU (+ AT, CH, DK)** → {@see self::MODE_AUTOCOMPLETE}. Every non-NL endpoint additionally REQUIRES a
 *    `street` (DE/FR/AT/CH/DK also a `settlement`), which a bare postcode + house-number query does not carry — so
 *    {@see self::lookup()} cannot resolve them from the base's current postcode-number flow (it returns a soft miss).
 *    Their real capability is {@see self::validate()}: a fully-typed address (street + city + postcode) is confirmed
 *    and canonicalised against the same endpoint. The honest frontend mode is therefore free-text autocomplete, not
 *    postcode-first autofill (same posture as the MyParcel satellite's Belgium handling).
 *
 * Pro6PP's `/suggest/*` endpoints are STRUCTURED (per-field: postcode → street → streetNumber), not a single free-text
 * term, so there is no clean mapping onto the base's free-text {@see self::suggest()} — it stays empty (inherited).
 *
 * Pro6PP is a commercial EU provider used only to resolve the checkout address, so it is functional/necessary and
 * declares no consent gate ({@see self::consentCategory()} inherited null); it stays configurable via the base's
 * per-provider `consent_category` hook. Pro6PP is PAID PER CALL — the base controller's rate limiting and the hardened
 * HTTP base (SSL verify ON, short timeout, graceful soft-miss) keep a dead or slow API from ever blocking checkout.
 *
 * @see https://api.pro6pp.nl/v2/openapi.json
 * @see https://pro6pp.nl/developer/autocomplete/nl/parameters
 */
class Pro6ppProvider extends AbstractHttpProvider
{
    /**
     * API base URI (includes the `/v2` version segment). Overridable in config to pin a mirror / sandbox host.
     */
    private static string $endpoint = 'https://api.pro6pp.nl/v2';

    /**
     * Environment variable holding the Pro6PP API key, sent as the `authKey` query parameter. No key → the provider is
     * inert (the registry skips it and the form stays manual), so the module is safe to install before credentials.
     */
    private static string $api_key_env = 'SS_PRO6PP_API_KEY';

    /**
     * The `/autocomplete/{cc}` query parameters each country endpoint accepts, used to BUILD a request that carries
     * only what that country honours (sending an unknown parameter risks an upstream 400). This key set also IS the
     * honest coverage list — {@see self::supportsCountry()} is membership of this map, so the module never claims a
     * country the API does not advertise. NL is the only endpoint that needs no `street` (the only structured flow).
     *
     * @var array<string, array<int, string>>
     */
    private const COUNTRY_PARAMS = [
        'NL' => ['postalCode', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'BE' => ['postalCode', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise', 'box'],
        'LU' => ['postalCode', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'DE' => ['postalCode', 'settlement', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'FR' => ['postalCode', 'settlement', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'AT' => ['postalCode', 'settlement', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'CH' => ['postalCode', 'settlement', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
        'DK' => ['postalCode', 'settlement', 'street', 'streetNumberAndPremise', 'streetNumber', 'premise'],
    ];

    public function getKey(): string
    {
        return 'pro6pp';
    }

    public function getName(): string
    {
        return 'Pro6PP';
    }

    /**
     * Configured once the API key is present in the environment. No key → inert, so the registry skips it and checkout
     * stays on the manual form.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return isset(self::COUNTRY_PARAMS[$this->normaliseCountry($countryCode)]);
    }

    public function lookupMode(string $countryCode): string
    {
        $code = $this->normaliseCountry($countryCode);
        if (!$this->supportsCountry($code)) {
            return self::MODE_NONE;
        }

        // NL resolves from a bare postcode + house number; every other country needs a street → free-text autocomplete.
        return $code === 'NL' ? self::MODE_POSTCODE_NUMBER : self::MODE_AUTOCOMPLETE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = $this->normaliseCountry($query->countryCode);

        // Only NL resolves structurally from a postcode + house number (the one endpoint that needs no street). Every
        // other country requires a street the postcode-number query does not carry, so there is nothing to call — a
        // soft miss leaves the form manual (their path is validate(), driven by a fully-typed address).
        if ($code !== 'NL' || !$query->hasPostcodeNumber()) {
            return null;
        }

        $data = $this->call('NL', [
            'postalCode' => $this->normalisePostcode($query->postalCode),
            'streetNumberAndPremise' => trim($query->houseNumber . $query->suffix),
        ]);

        return $this->isHit($data) ? $this->mapAddress($data, 'NL') : null;
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        $code = $this->normaliseCountry($address->countryCode);
        $postcode = $this->normalisePostcode($address->postalCode);
        if (!$this->supportsCountry($code) || $postcode === '') {
            return AddressValidationResult::unknown();
        }

        // Offer every field the address carries; call() keeps only the ones this country's endpoint accepts.
        $candidate = [
            'postalCode' => $postcode,
            'settlement' => $address->city,
            'street' => $address->street,
            'streetNumberAndPremise' => trim($address->houseNumber . $address->suffix),
        ];
        if ($code === 'BE' && $address->suffix !== '') {
            // Belgium carries the bus/box number separately from the house number.
            $candidate['box'] = $address->suffix;
        }

        $data = $this->call($code, $candidate);
        if (!$this->isHit($data)) {
            // A 404 (no such address) and a transport fault both arrive here as [] via the hardened HTTP base, so the
            // honest answer is "unknown" (a soft pass) rather than a false "invalid" that would block checkout.
            return AddressValidationResult::unknown();
        }

        return new AddressValidationResult(true, 1.0, $this->mapAddress($data, $code));
    }

    /**
     * GET `/autocomplete/{cc}` with the API key as the `authKey` query parameter. Parameters are filtered to the ones
     * the country endpoint accepts and emptied values dropped; a missing key short-circuits to [] (no call).
     *
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private function call(string $code, array $params): array
    {
        $key = $this->apiKey();
        if ($key === '' || !isset(self::COUNTRY_PARAMS[$code])) {
            return [];
        }

        $allowed = array_flip(self::COUNTRY_PARAMS[$code]);
        $query = ['authKey' => $key];
        foreach ($params as $name => $value) {
            if (isset($allowed[$name]) && $value !== '') {
                $query[$name] = $value;
            }
        }

        return $this->getJson($this->base() . '/autocomplete/' . strtolower($code), $query);
    }

    /**
     * Whether an `/autocomplete` response is a resolved address rather than an empty miss (a 404 decodes to [] via the
     * HTTP base). A resolved row always carries a postcode and a street.
     *
     * @param array<mixed> $data
     */
    private function isHit(array $data): bool
    {
        return ($data['postalCode'] ?? '') !== '' || ($data['street'] ?? '') !== '';
    }

    /**
     * Map one Pro6PP address object onto the normalised {@see ResolvedAddress}. `streetNumber` arrives as an integer;
     * `premise` (or Belgium's `box`) is the suffix; `settlement` is the city; the admin region is whichever of
     * province / state / region / canton / department the country returns. An autocomplete hit is always exact.
     *
     * @param array<mixed> $row
     */
    private function mapAddress(array $row, string $fallbackCode): ResolvedAddress
    {
        return new ResolvedAddress(
            street: $this->str($row['street'] ?? ''),
            houseNumber: $this->str($row['streetNumber'] ?? ''),
            // `premise` is the suffix; Belgium carries it in `box` instead (with `premise` returned empty), so take
            // the first NON-EMPTY of the two — `??` would keep an empty `premise` over a populated `box`.
            suffix: $this->firstNonEmpty($row, ['premise', 'box']),
            postalCode: $this->str($row['postalCode'] ?? ''),
            city: $this->str($row['settlement'] ?? ''),
            municipality: $this->str($row['municipality'] ?? ''),
            state: $this->region($row),
            countryCode: strtoupper($this->str($row['countryCode'] ?? $fallbackCode)),
            lat: $this->floatOrNull($row['lat'] ?? null),
            lng: $this->floatOrNull($row['lng'] ?? null),
            exact: true,
            providerRef: $this->getKey(),
        );
    }

    /**
     * The administrative region across Pro6PP's country schemas, in priority order: NL/BE/DK `province`, DE/AT `state`,
     * FR/CH/DK `region`, LU/CH `canton`, FR `department`. First non-empty wins; '' when none is present.
     *
     * @param array<mixed> $row
     */
    private function region(array $row): string
    {
        return $this->firstNonEmpty($row, ['province', 'state', 'region', 'canton', 'department']);
    }

    /**
     * The first non-empty string value among the given keys of a response row, or '' when none is populated.
     *
     * @param array<mixed> $row
     * @param array<int, string> $keys
     */
    private function firstNonEmpty(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->str($row[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function base(): string
    {
        return rtrim((string) static::config()->get('endpoint'), '/');
    }

    private function apiKey(): string
    {
        $env = (string) static::config()->get('api_key_env');

        return $env === '' ? '' : (string) Environment::getEnv($env);
    }

    private function normaliseCountry(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Compact, upper-cased postcode (spaces stripped): NL "2132 JE" → "2132JE", and a harmless no-op for the numeric
     * postcodes of the other countries.
     */
    private function normalisePostcode(string $postcode): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $postcode));
    }

    /**
     * A scalar response value as a string (handles the integer `streetNumber`), or '' for null / array / object.
     */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A numeric response value as a float, or null when absent / non-numeric.
     */
    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
