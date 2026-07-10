# Budgie — Envelope Budgeting App (YNAB-style)

A zero-based envelope budgeting system: every dollar you actually have gets assigned to a
category ("envelope"), you spend against envelope balances, and you rebalance by moving money
between envelopes. Built as three apps sharing one API:

| Layer | Tech |
|---|---|
| API | Laravel 13 (PHP 8.4), MariaDB, Sanctum auth, Redis (queues/cache) |
| Web | Nuxt 4 (Vue 3, TypeScript), Pinia, Tailwind CSS |
| Mobile | Flutter (Dart), Riverpod, pure online API client (no offline mode) |

---

## 1. Core Concepts & Rules (the domain)

These four rules drive every design decision below:

1. **Give Every Dollar a Job** — income lands in "Ready to Assign" (RTA); the user
   distributes it into categories until RTA = 0. You can only assign money you have.
2. **Embrace True Expenses** — targets/goals on categories let you drip-fund irregular
   expenses (e.g. $50/mo toward annual insurance).
3. **Roll With the Punches** — first-class "move money" between categories; overspending
   is surfaced, not punished.
4. **Age Your Money** — a computed metric: average age (in days) of the dollars being spent.

### Key derived numbers (never stored, always computed or cached with invalidation)

- **Category Available (per month)** = carryover from last month (if positive)
  + assigned this month + activity this month (activity is negative for outflows).
- **Cash overspending** (available < 0 in a cash category) resets to 0 at month rollover and
  reduces next month's RTA.
- **Credit overspending** rolls into credit card debt (see §4, the hardest mechanic).
- **Ready to Assign** = total inflows-to-budget across all cash accounts
  − total assigned across all months (future assignments count).
- **Age of Money** = for the last N spending transactions, average days between the outflow
  and the inflow(s) that funded it (FIFO over cash inflows).

---

## 2. Feature Set

### MVP (Phase 1–2)
- **Budgets**: a user can have multiple budgets; each has a currency and settings.
- **Accounts**: checking, savings, cash, credit card; on-budget vs tracking (off-budget)
  accounts. Manual accounts only at first (no bank sync).
- **Category groups & categories**: CRUD, reorder (drag & drop on web), hide/archive.
- **Monthly budget screen** (the heart of the app): per-month grid of
  Assigned / Activity / Available per category, RTA header, assign & move money inline,
  month navigation (past + future months).
- **Transactions**: inflow/outflow, payee, category, memo, cleared/uncleared flag, date;
  **split transactions**; **transfers** between accounts (paired transactions);
  scheduled/repeating transactions.
- **Move money**: envelope-to-envelope transfer dialog ("cover overspending from…").
- **Reconciliation**: enter the real bank balance, tick off cleared transactions,
  create an adjustment transaction if needed, lock reconciled transactions.
- **Credit card envelopes**: automatic payment-category mechanics (§4).
- **Auth**: register/login (Sanctum tokens for mobile, SPA cookie session for Nuxt),
  email verification, password reset, **MFA** (TOTP authenticator app + recovery codes),
  and **Google sign-on** alongside email/password. Mobile adds **biometric unlock**
  (Face ID / fingerprint) after first sign-in.

### Phase 3 — Quality of life
- **Targets/goals** per category: "needed for spending" (refill to $X monthly),
  "savings balance by date", "monthly savings builder". Progress bars + underfunded totals,
  "assign all underfunded" quick action.
- **Payee management**: rename/merge, per-payee default category (auto-categorisation).
- **Reports**: spending by category/payee (pie + trends), net worth over time,
  income vs expense, Age of Money chart.
- **CSV/OFX/QIF import** with duplicate detection (import_id hashing) and a
  column-mapping wizard.
- **Search & filters** on the transaction register.

### Phase 4 — Polish
- **Notifications**: overspending alerts, scheduled-transaction reminders, goal funded.
- CSV/OFX import is the *permanent* import path (decision: no bank-feed integration).

### Phase 5 — Sharing
- **Multi-user shared budgets** (invite a partner), roles, audit log — required feature
  (partner budgeting), scheduled last because everything else must be stable first.

### Explicitly out of scope (for now)
Investments/holdings tracking, multi-currency within one budget, loan amortisation planning,
receipt scanning.

---

## 3. Data Model (MariaDB)

All money is stored as **integer minor units** (cents) — never floats. If multi-currency
per budget ever lands, switch to milliunits like YNAB; cents is fine for a single-currency
budget.

**Keys — hybrid scheme**: every table uses a **BIGINT auto-increment `id`** as its primary
key, and all foreign keys are BIGINT references to it (fast joins, compact indexes,
standard Laravel `$table->id()` / `foreignId()`). API-exposed tables additionally carry a
server-generated **`uuid` column (UUIDv7, unique per table)** as the *public* identifier:
it's what the API exposes and routes on (`getRouteKeyName() => 'uuid'`), so sequential row
counts never leak. Internal relations never touch the uuid; it exists only at the API
boundary.

```
users            id, email, password nullable (social-only accounts), name,
                 provider nullable, provider_id nullable,        -- Google SSO identity
                 two_factor_secret, two_factor_recovery_codes,   -- encrypted (Fortify)
                 two_factor_confirmed_at
budgets          id, user_id, name, currency (ISO 4217), date_format, deleted_at
budget_users     budget_id, user_id, role            -- phase 4 sharing

accounts         id, budget_id, name, type enum(checking,savings,cash,credit,tracking),
                 on_budget bool, closed bool, note,
                 balance / cleared_balance (cached, maintained transactionally),
                 sort_order, deleted_at

category_groups  id, budget_id, name, sort_order, hidden, deleted_at
categories       id, budget_id, group_id, name, sort_order, hidden, deleted_at,
                 linked_account_id nullable   -- set for auto-created credit card
                                              -- payment categories

payees           id, budget_id, name, default_category_id nullable, deleted_at
                 -- transfer payees: transfer_account_id nullable

transactions     id, budget_id, account_id, date, amount (signed int, outflow < 0),
                 payee_id, category_id nullable, memo,
                 cleared enum(uncleared, cleared, reconciled),
                 approved bool (for imported txns awaiting review),
                 transfer_transaction_id nullable  -- pairs the two sides of a transfer
                 import_id nullable (unique per account, for dedupe),
                 scheduled_transaction_id nullable, deleted_at
sub_transactions id, transaction_id, amount, category_id, payee_id, memo
                 -- splits; parent's category_id is null when splits exist

scheduled_transactions  id, budget_id, account_id, frequency enum(...), next_date,
                        amount, payee_id, category_id, memo, deleted_at

monthly_budgets  id, budget_id, month (date, first of month), category_id,
                 assigned (int)                 -- the ONLY user-editable number
                 UNIQUE (budget_id, month, category_id)

targets          id, category_id, type enum(refill_monthly, balance_by_date,
                 monthly_builder), amount, target_date nullable, cadence
```

**Soft deletes everywhere** (`deleted_at`) — enables undo and keeps an audit trail; hard
deletion can be a periodic cleanup job if ever needed.

**Cached vs computed**: account balances are cached and updated inside the same DB
transaction as the mutation. Category "available" per month is computed on read from
`monthly_budgets.assigned` + transaction activity, with a per-(budget, month) cache in Redis
invalidated on any relevant write. Start computed-only; add the cache when the budget screen
gets slow.

---

## 4. The Hard Mechanics (get these right early)

### Credit cards — the signature YNAB behaviour
When you create a credit card account, auto-create a **Credit Card Payment category**
linked to it. Then:

- Spending $50 on the card from category "Groceries" (which has money available):
  the system **automatically moves $50 of budgeted-for money** from Groceries' available
  into the CC Payment category. Your cash didn't leave, so the payment envelope holds the
  cash you'll use to pay the card.
- Spending on the card from an **unfunded** category = credit overspending → the CC Payment
  category does *not* get the move; the debt simply grows (surfaced as yellow overspend).
- Paying the card = a **transfer** from checking → credit account, which draws down the CC
  Payment category's available.

Implement this as a **derived calculation, not stored moves**: CC Payment activity for a
month = (budgeted spending on that card that month, capped per-transaction at what the
category could cover) + assigned directly to it + transfers in/out. Storing phantom
"move" rows is how you get corruption when a transaction is edited retroactively. Write
exhaustive tests for: edit/delete of a card transaction months later, recategorisation,
refunds (inflow on a card), and partial funding.

### Month rollover
No batch job. Months are **virtual**: the budget screen for month M is computed from all
assignments and transactions ≤ M. Positive available carries forward; negative-cash
available does not (it reduces RTA); negative-credit available rolls into card debt.
This makes retroactive edits (add a forgotten transaction from two months ago) just work.

### Ready to Assign
Single formula evaluated per month, property-test it:
`RTA(M) = inflows-to-budget through M − Σ assigned through M − future assigned − cash overspending corrections`.
Every mutation that could change RTA (transactions, assignments, account creation with
starting balance) goes through domain services — never raw model writes from controllers.

### Transfers
One logical transfer = two transaction rows linked by `transfer_transaction_id`, created
and updated atomically. On-budget → on-budget transfers have no category (money didn't
leave the budget). On-budget → off-budget/tracking transfers **do** take a category
(money left the budget). Credit card payments are transfers.

---

## 5. API Design (Laravel)

- **REST, versioned**: `/api/v1/...`, budget-scoped:
  `/api/v1/budgets/{budget}/accounts`, `.../categories`, `.../transactions`,
  `.../months/{yyyy-mm}` (the full budget-screen payload for a month),
  `.../payees`, `.../scheduled-transactions`.
- **Auth**: Sanctum. Cookie-based SPA auth for Nuxt (same-site), personal access tokens
  for Flutter. **Fortify** provides registration/reset **and two-factor auth** (TOTP
  secret + QR enrolment, recovery codes, 2FA challenge on login) — the same flows serve
  both clients via JSON. **Google sign-on via Socialite**: web uses the standard OAuth
  redirect flow; Flutter uses the `google_sign_in` package and posts the resulting Google
  ID token to `POST /api/v1/auth/google` for verification + Sanctum token issuance.
  Accounts are linked by verified email, and a `provider`/`provider_id` pair on `users`
  records the Google identity; password is nullable for social-only accounts. MFA applies
  to password logins; Google logins delegate 2FA to Google.
- **Money-moving endpoints** are explicit actions, not generic PATCHes:
  - `POST .../months/{month}/categories/{category}/assign` `{ amount }`
  - `POST .../months/{month}/move-money` `{ from_category, to_category, amount }`
  - `POST .../accounts/{account}/reconcile` `{ statement_balance, cleared_ids }`
- **Idempotency**: `import_id` dedupes file imports; a bulk endpoint
  `POST .../transactions/bulk` for imports.
- **Layering**: Controllers → Form Requests (validation) → **Domain services**
  (`AssignMoney`, `RecordTransaction`, `ReconcileAccount`, `MoveMoney`) → Eloquent.
  All money math in a `Money` value object (int cents). Every mutation wrapped in a DB
  transaction that also touches cached balances.
- **Policies** on every model keyed by budget membership.
- **Testing**: Pest. The domain services and the RTA/CC/rollover math get the densest
  coverage — property-style tests (random transaction sequences must always satisfy
  invariants like `Σ envelope available + RTA = Σ on-budget cash`).

## 6. Data Freshness (purely online — no offline mode)

Both clients are thin online clients; **the API is the single source of truth and the only
place budget math lives**. No local replicas, no mutation queues, no math duplicated in
Dart or TypeScript — clients render server-computed values (`available`, RTA, balances)
returned by the month/account endpoints.

- **Nuxt**: Pinia stores hydrated per screen; after any mutation the API response returns
  the recalculated month payload so the UI updates without a second round-trip.
- **Flutter**: Riverpod providers fetch on navigation with stale-while-revalidate caching
  in memory; pull-to-refresh on register/budget screens. Graceful "you're offline" state —
  read-only cached views at most, never queued writes.
- **Later**: Laravel Reverb (websockets) to push "budget changed" events so a phone and a
  browser open at the same time stay live — a nice-to-have in Phase 5, not core.

## 7. Frontend Plans

### Nuxt (web — the power-user surface)
- Nuxt 4 + TypeScript, Pinia for the budget/transaction stores, Tailwind + Headless UI.
- Key screens: **Budget** (month grid — inline editable Assigned cells, category rows with
  progress bars, right sidebar for the selected category's target/quick actions),
  **Accounts/Register** (virtualised transaction table, inline add/edit row, bulk edit,
  running balance), **Reports**, **Reconcile flow**, **Settings**.
- Keyboard-first register entry (YNAB's killer feature): tab-through fields, payee/category
  autocomplete with fuzzy match, `Enter` saves + opens a new row.
- Currency input/display via a shared formatting util; all amounts move over the wire as
  integer cents.

### Flutter (mobile — the capture surface)
- Optimised for the 10-second use cases: **add a transaction** (big FAB, payee/category
  autocomplete, geolocation-suggested payee later), **check an envelope balance**,
  **approve imported transactions**, quick move-money.
- Riverpod for state, `dio` + generated API client for networking, `go_router` for
  navigation. No local database — in-memory caching only.
- **Biometric login**: full sign-in (password/MFA or Google) happens once; the Sanctum
  token is stored in `flutter_secure_storage` (iOS Keychain / Android Keystore) and
  subsequent app opens are gated by a `local_auth` biometric prompt (Face ID/fingerprint,
  device PIN fallback) before the token is read. It's a device-level unlock, not a server
  auth method — the API never sees biometrics. Failed/unavailable biometrics fall back to
  the normal login screen; sign-out and server-side token revocation wipe the stored token.
- Same information architecture as web but bottom-nav: Budget / Accounts / Add / Reports /
  Settings.

## 8. Repo & Delivery

Monorepo:

```
budgie/
  api/      Laravel
  web/      Nuxt
  mobile/   Flutter
  shared/   API spec (OpenAPI)
  docker-compose.yml   (mariadb, redis, mailpit, api, web)
```

- **OpenAPI spec** generated from Laravel (e.g. Scribe/Scramble) → typed client for Nuxt
  and (via openapi generators) Dart models for Flutter.
- CI: Pest + PHPStan (api), Vitest + typecheck (web), flutter test (mobile).

## 9. Phased Roadmap

| Phase | Scope | Outcome |
|---|---|---|
| **0. Skeleton** | Monorepo, docker-compose, Laravel + Sanctum auth, Nuxt shell with login, CI | Log in on web |
| **1. Budget core (API+web)** | Budgets, accounts, categories, transactions (incl. splits/transfers), monthly_budgets, RTA + available math, budget screen, register | Usable single-device web budget |
| **2. The hard mechanics** | Credit card categories, reconciliation, scheduled transactions, move-money UX, month navigation | Feature parity with core YNAB loop |
| **3. Mobile** | Flutter app (register + budget + add-transaction) as a pure online client | Phone capture, web planning |
| **4. QoL** | Targets/goals, reports, CSV/OFX import, payee rules, search | Daily-driver replacement for YNAB |
| **5. Sharing & live** | Shared budgets (partner invite), notifications, Reverb live sync | Multi-user budgeting |

## 10. Decisions (resolved 2026-07-10)

1. **Single currency per budget**, AUD default.
2. **No bank feeds** — CSV/OFX import is the permanent import path.
3. **Multi-user sharing is required** (partner budgeting) — scheduled as Phase 5.
4. **Hosting mirrors StaceLib** (`../stacelib`): a single VPS running
   `docker-compose.prod.yml` — Caddy (Cloudflare DNS TLS) reverse-proxying `api` and
   `web` containers, a `worker` container for queues, a scheduler, MariaDB and Redis —
   deployed via per-app GitHub Actions workflows (`api.yml`, `web.yml`, `mobile.yml`).
