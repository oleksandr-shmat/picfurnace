# PicFurnace

A small product on top of **Pixelforge**, an asynchronous image-generation API. Users sign in, describe an image, see **what it will cost before they send it**, then follow the generation live (queued, generating, done or failed) and see the exact price and the image.

Laravel 13, Vue 3, Inertia 3, PostgreSQL. The price a user pays is the Pixelforge cost plus a markup (25% by default). A generation that fails is free.

- **Deployed:** _URL to be added_
- **Demo account:** `demo@example.com`, password in the submission e-mail (locally `password`)
- **Admin account:** `admin@example.com` (only used by the optional parts, see [Roadmap](#roadmap))

## What it does

1. **Studio page.** Prompt, size, quality and up to 4 reference-image links. A price range (minimum, most likely, maximum) follows the form while you type.
2. **Own queue in front of Pixelforge.** A generation waits in our queue, can be cancelled for free while it waits, and is sent when a slot is free.
3. **Live status.** The page polls our own database (never Pixelforge) while something is unfinished.
4. **Exact price and image.** The estimate and the final price are shown side by side. Images are kept for 14 days on our own storage (Pixelforge links die after 24 hours) and the page says until when.

## Run it locally

Requirements: PHP 8.4+ (developed on 8.5), Node 22, Docker (for PostgreSQL), Composer.

```bash
# PostgreSQL on port 5433 (what .env.example and phpunit.xml expect)
docker run --name promptedit_pg -d -p 5433:5432 \
  -e POSTGRES_USER=pixelforge -e POSTGRES_PASSWORD=pixelforge -e POSTGRES_DB=pixelforge postgres:17
docker exec promptedit_pg createdb -U pixelforge pixelforge_test   # for the tests

cp .env.example .env
composer install && npm install
php artisan key:generate
# put your Pixelforge API key and webhook secret in .env: PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET
php artisan migrate --seed          # creates the demo user and the admin

npm run build                       # or `npm run dev` while working on the frontend
php artisan serve                   # http://localhost:8000
php artisan queue:work              # sends a generation right after it is submitted
php artisan schedule:work           # safety net, reconciliation, image copies
```

Webhooks need a public URL. Locally use a tunnel (for example `cloudflared tunnel --url http://localhost:8000`) and set `https://<tunnel>/webhooks/pixelforge` as the webhook URL of your Pixelforge key. Without it everything still works: the reconciler asks Pixelforge about generations that stay silent for 3 minutes.

### Checks

```bash
composer test        # code style (Pint), static analysis (PHPStan level 7), tests
composer coverage    # line coverage, fails below 97%
composer mutate      # mutation testing, fails below 91%
npm run check        # formatting and lint
npm run types:check  # TypeScript / Vue
npm test             # frontend unit tests (Vitest)
```

CI (`.github/workflows/tests.yml`) runs all of them against a PostgreSQL service.

## Configuration

| Variable                                          | Meaning                                                              | Default   |
| ------------------------------------------------- | -------------------------------------------------------------------- | --------- |
| `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` | One key per environment: each key has its own webhook URL and secret | —         |
| `BILLING_MARKUP_PERCENT`                          | Whole percent added to the Pixelforge cost                           | 25        |
| `PIXELFORGE_MAX_UNFINISHED_PER_USER`              | Generations a user can have waiting or running                       | 5         |
| `PIXELFORGE_MAX_CONCURRENT_GENERATIONS`           | Pixelforge allows 3 at once for the whole account                    | 3         |
| `IMAGE_DISK`, `IMAGE_RETENTION_DAYS`              | Where images are copied, and for how long                            | local, 14 |
| `DEMO_USER_PASSWORD`, `ADMIN_PASSWORD`            | Seeded accounts. Required in production                              | —         |

## How money is handled

- **Integers only.** All money is stored as whole **nanodollars** in `bigint` (Pixelforge reports costs with nine decimals), so sums are exact. `moneyphp/money` does the arithmetic; `App\Money\Usd` is the single place that parses, formats and persists amounts. It refuses what the library accepts silently (more than nine decimals, `""`, `.5`).
- **Cost** = tokens x per-token rate. A rate that is not a whole number of nanodollars per token is rejected, not rounded.
- **Price** = cost + markup, computed as `cost x (100 + percent) / 100` in integers with one half-up rounding, so it is at most half a nanodollar from the exact price. The markup percent is **stored on every generation**, so the estimate and the final charge use the same percent even if the config changes later.
- **Only a succeeded generation is charged**, from the `usage` and `rates` of the result. Rates are fixed per generation by Pixelforge, so later price changes do not matter.
- **Verified against the provider.** The 12 generations recorded during development add up to exactly what `GET /v1/usage` reported (`1.297507500`).

## Webhooks: duplicated, late, lost

Pixelforge delivers events at least once, in any order, and sometimes before our own request has returned.

- The signature is checked on the **raw body** (HMAC-SHA256, 5 minutes tolerance). Without a configured secret the endpoint refuses to work.
- **Deduplicated** by event id in the same database transaction that applies the event, so a failure rolls the event back and the retry is processed.
- **Order-safe.** A status only moves forward by rank; a late `processing` after `succeeded` changes nothing, and a success is charged **exactly once** (row lock plus the rank rule). Two tests with a second PHP process and a real PostgreSQL lock prove it.
- **Matched by our own uuid** sent in the `metadata`, so an event that beats the response of our `POST` is still applied.
- **Lost webhooks:** a reconciler asks Pixelforge about generations that stay silent for 3 minutes (at most 10 a minute, because the request limit is shared by all users).

## Shared limits of the Pixelforge account

Pixelforge limits the **whole account**, across all keys and users: 3 concurrent generations, 60 requests a minute, 500 a day. So users must not be able to starve each other. `GenerationDispatcher` is our own queue: oldest first, one active generation per user, at most 3 active in total, a pause of all sending when a limit is hit (until the end of the UTC day for the daily one), and backoff with `Retry-After` for overload. A generation is "claimed" just before it is sent, and from then on it can no longer be cancelled for free, because it may already exist at Pixelforge.

## Architecture

```
app/
  Money/          Usd: the money boundary
  Pixelforge/
    Client/       HTTP client, typed errors (RetryLater, RequestRejected, UnexpectedResponse), cached pricing
    Api/          Normalisation of API responses: the only code that touches raw JSON
    Webhook/      Signature check, event parsing
  Quoting/        Estimate of the cost, markup, the price shown before sending
  Billing/        The seam for money movement (reserve / settle / release)
  Generations/    Submit, dispatch, lifecycle (status + charge), reconcile, images, webhook handling
  Jobs/           DispatchGenerationsJob
  Http, Console, Models, Policies, ...
```

- **Normalisation boundary.** `fromArray` of the `Api` and `Webhook` classes turns untyped JSON into typed values and throws on anything unexpected (a succeeded generation without usage is refused, never charged as zero).
- **`GenerationLifecycle`** is the only place that changes a status or charges a user.
- **Dispatch is a queued job, with a watchdog.** `DispatchGenerationsJob` sends waiting generations right after one is submitted and whenever one finishes, and it is queued with a delay for the moment a retry is due or a pause ends. The scheduler runs the same dispatcher every minute only as a watchdog for whatever gets stuck (a lost job, a worker that was down), so the app also works, a little slower, without a queue worker.
- **Estimate.** Output and reference-image tokens are exact. Text tokens are `ceil(characters / 4)` within the documented 15%, reasoning tokens fall in a range per quality; bounds are rounded outwards.

## Decisions and trade-offs

- **No registration.** Accounts come from the seeder. The sign-in page says accounts are created by an administrator. Registration, password reset by e-mail, verification, two-factor and passkeys of the starter kit were removed on purpose: a workflow nobody needs here.
- **Users are soft deleted** and history is never hard deleted (`RESTRICT`): billing history must keep matching `/v1/usage`.
- **No cancel endpoint at Pixelforge.** Once accepted, a generation runs and is billed on success. The form says so. Free cancellation exists only while it waits in our queue.
- **Pricing is cached** for 5 minutes and served stale for up to an hour (the request limit is shared). Charges use the rates of the generation, not the cache.
- **Provider outage while sending.** After 5 failed attempts a generation fails for free. If the connection drops exactly while Pixelforge accepts a request and stays down through all attempts, Pixelforge may complete a generation we failed; this is logged as `critical`.
- **The scheduler needs a worker process** (`queue:work`) for instant sending; without it the dispatcher still runs every minute.
- **Images are copied** to our disk for 14 days (configurable) and served only to their author or an admin.

## Quality: what the numbers mean

- **Line coverage 98.7%** (PCOV). It says a line ran, not that anything was checked.
- **Mutation score about 92%** over the whole backend: the code is changed automatically (`>` to `>=`, a removed call) and the tests must notice. The survivors are constants and equivalent changes, reviewed by hand.
- Recorded Pixelforge responses are used as fixtures, and expected numbers in tests are computed independently of the code.

**Not covered, said plainly:** there are no browser tests (the frontend has unit tests for its plain logic, lint and types, plus manual checks); concurrency is covered by two targeted tests, not a general harness; Pixelforge itself was checked by hand against the live API; PCOV has no branch coverage.

## Deploying (Laravel Cloud)

The app runs on Laravel Cloud: PHP 8.5, serverless PostgreSQL, an object-storage bucket.

- **Build commands:** `composer install --no-dev`, `npm ci --audit false`, `npm run build`, `php artisan optimize`
- **Deploy commands:** `php artisan migrate --force`, then `php artisan db:seed --force` (the seeder is safe to run again)
- **Scheduler:** enable the Scheduler toggle (runs `schedule:run` every minute).
- **Queue worker:** one worker for the default queue (`QUEUE_CONNECTION=database`), so a generation is sent right after it is submitted. Without it the scheduler still sends it within a minute.
- **Bucket:** attach it as the default disk. Cloud then sets `FILESYSTEM_DISK` and the `AWS_*` variables, and image copies go there (the local disk of Cloud is ephemeral).
- **Environment variables:** `APP_ENV=production`, `APP_KEY`, `APP_URL`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `DEMO_USER_PASSWORD`, `ADMIN_PASSWORD` (the seeder refuses to run in production without the passwords).
- **Pixelforge key:** create a key for this environment and set its webhook URL to `https://<app>/webhooks/pixelforge`.
- **Deploy hook:** with the hook enabled and its URL in the repository secret `LARAVEL_CLOUD_DEPLOY_HOOK`, the `deploy` job of the workflow triggers a deploy only after every check of the same commit has passed.

## Roadmap

The core was built so these plug in without rewrites:

1. **Balances.** An append-only credit ledger, a fake "Buy credits", reserve of the maximum estimate and refusal when the user cannot pay. Only the `Billing` implementation changes (`NoBalanceBilling` today); the generation flow already calls `reserve`, `settle` and `release`.
2. **Admin report.** Per UTC day and per user: charged, provider cost, profit; provider cost reconciled with `GET /v1/usage`.
