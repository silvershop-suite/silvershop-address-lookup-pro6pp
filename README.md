# silvershop/address-lookup-pro6pp

A **satellite provider** for [`silvershop/address-lookup`](https://github.com/silvershop/silvershop-address-lookup)
(CMS 6) that resolves and validates addresses through the **[Pro6PP v2 API](https://pro6pp.nl/developer)**.

It registers one provider (`pro6pp`) in the base module's per-country registry and — out of the box — **fills the gaps
no other provider in the suite covers**: it maps `FR → pro6pp` and `LU → pro6pp` (France and Luxembourg). The free /
government defaults are deliberately left untouched (`NL → pdok`, `DE → openplz`, `BE → myparcel` when that satellite is
installed).

> **Pro6PP is PAID per call.** The base controller rate-limits the endpoint and the hardened HTTP base keeps a slow or
> dead API from ever blocking checkout, but every resolved lookup/validate is a billable request against your Pro6PP
> account.

## Capabilities

| Country | Mode | Endpoint | Resolves |
| --- | --- | --- | --- |
| **NL** | `postcode_number` | `GET /v2/autocomplete/nl?postalCode=&streetNumberAndPremise=` | **street + settlement (city) + municipality + province** (+ lat/lng) from a postcode + house number |
| **BE, DE, FR, LU** (+ AT, CH, DK) | `autocomplete` | `GET /v2/autocomplete/{cc}?postalCode=&street=(&settlement=)` | **validation / canonicalisation** of a fully-typed address (these endpoints require a `street`) |

The API key is sent as the **`authKey` query parameter** (not a header). It rides the query string of the
**server-side** request only — the base module proxies the lookup, so the key is **never exposed to the browser**.

> **Why is only NL `postcode_number`?** Pro6PP's `/v2/autocomplete/nl` is the only endpoint that needs nothing but a
> `postalCode` (+ optional `streetNumberAndPremise`), so a Dutch postcode + house number resolves straight to a full
> structured address. Every other country's endpoint additionally **requires a `street`** (DE/FR/AT/CH/DK also a
> `settlement`), which a bare postcode + house-number query doesn't carry. So for those countries Pro6PP's honest mode
> is free-text autocomplete, and its working capability is **`validate()`** — confirming and canonicalising an address
> the customer has already typed in full. (The base checkout bootstrap currently drives only the `lookup` endpoint; a
> free-text suggest UI for `autocomplete` countries is a base-module concern, same as for the MyParcel satellite.)

## Installation

```bash
composer require silvershop/address-lookup-pro6pp
```

Then run `dev/build?flush=all`.

### API key

Set your Pro6PP API key in the environment as **`SS_PRO6PP_API_KEY`**:

```dotenv
# .env
SS_PRO6PP_API_KEY="your-pro6pp-api-key"
```

Without a key the provider is **inert**: the registry skips it and the checkout form stays manual, so the module is
safe to install before credentials are supplied.

## Configuration

Registration and the FR/LU mapping are applied automatically by `_config/address-lookup-pro6pp.yml`:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    pro6pp: '%$SilverShop\AddressLookup\Pro6pp\Pro6ppProvider'
  by_country:
    FR: pro6pp
    LU: pro6pp
```

**The free / better defaults are deliberately left in place** — `NL → pdok` (free government BAG geocoder),
`DE → openplz` (free), `BE → myparcel` (when installed). Pro6PP is still **registered and available** for those
countries; to route any of them to Pro6PP instead, override `by_country` in your project config:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  by_country:
    NL: pro6pp   # use Pro6PP for NL instead of the free PDOK geocoder
    BE: pro6pp
    DE: pro6pp
```

Pro6PP also covers **AT, CH and DK** — map any of them the same way if you want Pro6PP to handle them.

### Provider options

```yaml
SilverShop\AddressLookup\Pro6pp\Pro6ppProvider:
  endpoint: 'https://api.pro6pp.nl/v2'   # override to pin a mirror / sandbox host
  api_key_env: 'SS_PRO6PP_API_KEY'       # the env var holding the key (sent as the authKey query param)
  timeout: 5                             # upstream timeout, seconds (inherited from the HTTP base)
```

## Consent

Pro6PP is a commercial EU provider used **only to resolve the checkout address**, so the provider is
**functional/necessary** and declares **no consent category** — it runs without a cookie gate (same posture as the base
module's bundled providers). If your legal setup needs it gated, set a category via the base's per-provider hook:

```yaml
SilverShop\AddressLookup\Pro6pp\Pro6ppProvider:
  consent_category: 'marketing'   # require this cookieconsent category before any Pro6PP call
```

## Hardening

Inherited from the base module's `AbstractHttpProvider`: **SSL verify ON**, a short upstream timeout, and a graceful
empty answer on any miss or fault, so a slow or failing address API never blocks checkout. The lookup endpoint itself
(POST-only, same-origin + CSRF, rate-limited, PII in the request body never the URL) lives in the base module.

## Requirements

- PHP ^8.3
- silverstripe/framework ^6
- silvershop/core ^6
- silvershop/address-lookup ^1
- A Pro6PP API key (`SS_PRO6PP_API_KEY`) — paid per call

## License

BSD-3-Clause.
