# LESSONS.md — Lessons from building with an AI agent

Compiled from the end of Phase 2 of the Smaily Connect WordPress plugin, where fixing
integration bugs took ~19 iterations. Much of that was avoidable. This document is meant
to be carried into **the start of the next project** and handed to the **Code agent**
so the same patterns don't repeat.

---

## TL;DR — three things from day one

1. **Build marker** — commit hash visible at runtime (console / footer / `/version`).
   Kills the "is it a bug or stale cache?" ambiguity.
2. **Agent sees the real environment** — Docker / wp-env / browser / real API in the
   agent's hands **before** coding starts, not later.
3. **Integration tests at the boundaries** — every "done" piece needs one end-to-end
   flow test in a real environment, not just unit coverage.

Plus two non-technical:
- **Domain walkthrough catches spec errors** that no test catches.
- **Live probe before coding** when a contract detail (field name, payload location,
  response shape) is undocumented — a 5-minute curl against a two-system assumption
  saves a day of iteration later.

---

## 1. Root cause: the integration layer was systematically untested

All the late-Phase-2 pain reduces to one thing. Backend logic was strong (95% unit
coverage, 140 PHPUnit + 96 Vitest tests, all green). But **boundaries between
components** were mocked, not actually tested.

Every bug lived at a **boundary**, not inside a component:

| Bug | Boundary |
|-----|----------|
| `restRoot` vs `restUrl` field mismatch | PHP ↔ TypeScript |
| Wizard didn't call save | Wizard ↔ backend |
| Legacy hook crash on REST save | New code ↔ legacy code |
| Backfill DB table missing | Plugin ↔ WP migration (dbDelta) |
| Workflows returned mock data | Plugin ↔ Smaily API |
| Writes with key X, reads with key Y | Write ↔ read |

**General rule: mocks hide boundaries. Integration bugs live at boundaries.**
The more components in the system (here: React + REST + WP + WC + Polylang + legacy +
Smaily API), the more boundaries, the more important real integration testing.

---

## 2. Biggest time sinks, ranked

### 2.1 Cache ambiguity (most expensive)

**What happened:** repeatedly "isn't saving" → reinstall → sometimes works. We didn't
know whether staging was even running the right code. Every "doesn't work" was
ambiguous: bug or stale code?

**Fix (found too late):** `buildHash` in the boot payload. ~5 lines of code. A console
check `window.appBoot.buildHash === "abc1234"` confirms in a second whether the right
code is running.

**Rule for next time:** every deployable project needs a build marker **from day one**.
Commit hash (with `-dirty` flag if the working tree is modified) visible at runtime.
Without it, all staging debugging is blind.

### 2.2 Agent couldn't see the real environment (second most expensive)

**What happened:** the agent fixed CSS and integration logic **blindly** — guessed a
fix, the human tested in staging, broke, repeat. The alignment bug took 5 attempts.
The backfill bug (missing DB table) would **never** have been resolved through the
staging cycle, because you can't see it without DB access.

**Fix (found too late):** Docker + wp-env + Chromium in the agent's environment. After
that the agent saw real WP itself, read debug.log, reproduced bugs. The backfill bug
resolved on the first wp-env run (debug.log showed the SQL error immediately).

**Rule for next time:** if the agent is building something that runs in environment X,
the agent must have **access to environment X from day one**. WP plugin → wp-env.
React app → browser. API → real HTTP test. Blind fixing is slow and imprecise.

### 2.3 Mock tests created false confidence (root cause behind most bugs)

**What happened:** all unit tests green, but every integration bug got through. Mocks
test a function **in isolation**, not the flow **end to end**. "Green test" ≠
"working feature."

**Fix:** the agent started **running full flows in the browser (Chromium)** before
every ZIP — the same thing a human would do manually (step 1 → 6, close, reopen, check
persistence). After that: the first clean walkthrough.

**Rule for next time:** green unit tests ≠ working feature. Every "done" sub-PR needs
**one end-to-end flow test in a real environment**, not just unit coverage. Units for
logic (fast, valuable), integration for boundaries (which was missing initially).

### 2.4 Mocks reflect your assumptions, not reality

**What happened** (Phase 3 sub-PR 3.1.2): the plugin called the rec engine with path
`/setup/exchange`, the mock server cheerfully responded to `/setup/exchange`. Both
tests green. The real engine, however, required `/api/setup/exchange` — so plugin ×
engine compatibility was **broken**, even though the mock said "all OK." The mock was
**built around the same assumption** as the plugin (wrong path), so it confirmed the
assumption, not reality.

**The same pattern repeated in 3.2** — the engine added `event_id` acceptance in the
catalog body (commit 985c488), but the **location in the body was undocumented**
(per-product vs top-level). A mock could be built around either variant and stay
green; the real engine accepts only one.

**Fix:** when a contract detail is **undocumented** or **recently added**, do a
**live probe** before coding — two curl calls against the real endpoint, see which
gets 200 / which gets 4xx. Lock against the real engine's response, not the mock
assumption. Then build the mock to **match the real behavior**.

**Rule for next time:** mocks test plugin logic **against mock assumptions**. Only a
**live call against the real system** tests real compatibility. So **both are
needed**, but **a live call is mandatory** before ZIP/merge, not "if we get to it."
The path bug should have been resolved with five minutes of curl, five days before
the live test caught it.

**Third time, same pattern** (sub-PR 3.2.2): the mock engine's setup-exchange
returned its endpoints map with **unprefixed keys** (`catalog`) and **relative
paths** — built around the plugin's original assumption. The real engine returns
`ingest_catalog` keys with **absolute URLs** (verified in the 3.1.2 live exchange).
A `Client::ingest_catalog` reading `endpoints()['ingest_catalog']` would have passed
every mock test and resolved a null URL in production. The fix rebuilt the mock to
the engine's live shape. One way to *enforce* this going forward: seed the mock from
a captured real setup-response, or periodically sync it against an engine-team
fixture, so the mock can't drift back toward plugin assumptions.

### 2.5 Context-vs-code audit at the start of a new session

**What happened** (Phase 3 sub-PR 3.2): the agent took the session context summary,
started with the plan, **but checked it against the real code first**. Found 3
divergences: (a) names in the plan were already taken in another module, (b) the DB
schema was already in place (migration committed), (c) an out-of-date architecture
doc described an old approach predating a recent design decision.

**This is the right behavior.** After long gaps (session switch, restart, memory
refresh), the **context summary is a risk** — it's a human's understanding, not the
code's actual state. Blind-coding from it leads to drift that staging tests catch
(= slow + expensive feedback loop).

**Rule for next time:** at the start of a new session, **before writing a single line
of code**, the agent must:
1. `git log` — where are we actually (commit hash + messages)
2. Look at existing components against the context plan (are the names free? does the
   table exist? is the doc current?)
3. Report any divergences **before** locking the plan

An honest context-code audit saves hours of later rewriting.

### 2.6 Test environment state is not persistent

**What happened** (Phase 3 sub-PR 3.2 setup): the plugin ran setup-exchange against
the real engine (in 3.1.2), and api_key was saved encrypted in `wp_options`. A few
weeks later, when 3.2 coding started, the agent checked the DB → **all `smly_rec_*`
keys were gone**. Migration tables were still there (activation hook on every boot),
but the `wp_options` connection state had been wiped.

The cause: `wp-env` recreates the WordPress DB on certain `start`/`stop` cycles —
this is **documented behavior**, not a bug. But it means **every sub-PR** that needs
a "connected" state (setup-exchange result, saved settings, integration test
fixtures) has to account for the fact that **state can disappear on any restart**.

**Solution** (two layers):

1. **Fixture bootstrap** in the test suite — `EnvSeed.php` (opposite of `EnvScrub`)
   establishes the required state at test start, using the plugin's own save API with
   mock data. Gives integration tests a "connected" state **without needing a real
   API call**.
2. **Live tests preserve real data** — Chromium walks with the real API key (which a
   human re-mints if lost) verify real compatibility.

**Rule for next time:** test environment state is not persistent. Any state tests
depend on must come from a **fixture** (regenerable on every run) or a **persistent
volume** (DB snapshot that survives restarts). Don't rely on the wp-env DB.

This is a **general rule** for other container-based dev environments too (Docker
Compose, dev server, Vercel preview) — restart wipes state unless it's been
explicitly persisted. Design tests to be restart-proof.

### 2.7 A spec sync updates the doc, not the code

**What happened** (Phase 3 sub-PR N-7.1): the catalog ingest wire wrapper key
flip-flopped. The doc originally said `products`; a 3.2.4 live probe found the
deployed engine then wanted `items` (we switched the plugin + mock). Later **W2**
(engine `b5b1295`) renamed it **back to `products`** — a clean break, an
`items`-wrapped payload now `400`s. The W2 contract **sync updated the doc**
(byte-identical, CC-8 discipline) **but not the plugin code**: `Client::ingest_catalog`
kept sending `{items:[...]}`. The mock — still enforcing `items` from the pre-W2
shape — **stayed green on every integration run**, so the drift was invisible. It
sat from W2 through W3/W4/W5/N-6/N-7 until the **first catalog live-request after
W2** (the N-7.1 catalog live-walk) `400`d on `products: Required`.

**Why the other endpoints were safe:** customers and orders each got a live-walk
**right after** their contract change (customers after W4, orders after W5), so any
drift would have surfaced immediately. Catalog was the unique case — its breaking
change (W2) and its next live-walk (N-7.1) were far apart. **The gap is exactly
(contract-change → next live-walk of that endpoint); the longer it is, the longer a
drift hides.**

**Two failure modes a wire-shape change can cause:**
- **Missing/renamed required field** (wrapper key, a newly-required field) → hard
  `400`. This is what bit catalog.
- **Removed field still sent** (W3 `discount_price`, W4 `smaily_contact_id` / `consent`)
  → Zod **silently strips** it (W1 confirmed: a stray top-level `event_id` is ignored).
  No `400`, but silent waste / privacy surprise. A full audit must check **both**.

**Fix / rule for next time:** a contract **sync is not code-complete**. Every sync
that changes a **wire shape** (wrapper key, a required field, an enum value, a removed
field) must be checked **against the real plugin code in the same pass**, and the
**mock must move to the new shape in the same sync** — otherwise the mock masks the
very drift it exists to catch. After any sync touching an endpoint, run that
endpoint's live-walk (or a single curl) before treating it as done; don't let a
breaking change wait for an unrelated sub-PR to discover it. When auditing, walk the
**whole changelog**, not just the most recent sync — drift accumulates silently across
syncs whenever the endpoint isn't live-exercised between them.

### 2.8 PHPStan as a storage-assumption (HPOS) bug detector

**What happened** (3.8.0 GDPR): the GDPR exporter/eraser read order rec-meta with
`get_post_meta( $order_id, '_smaily_rec_id' )`. PHPStan flagged an unrelated line
— casting `wc_get_orders( [ 'return' => 'ids' ] )` results to int ("Cannot cast
WC_Order to int", because the stub types the return as `WC_Order[]`). Chasing
*why* the types didn't line up — instead of mechanically silencing it — surfaced
a real bug: **`get_post_meta` / `delete_post_meta` only work under legacy
(posts) order storage.** Under **HPOS** (High-Performance Order Storage — the
wp-env + WC-10.7 mode, and a store can enable it at WC 8.2+) order meta lives in
`wc_orders_meta`, so the post-meta calls would silently read/erase **nothing** —
a partial GDPR export and an incomplete erase, both failing *quietly*.

The fix is storage-agnostic WooCommerce APIs: `$order->get_meta( $key )`,
`$order->delete_meta_data( $key )` + `$order->save()` — they resolve to whichever
backend is active.

**Why it nearly slipped:** the unit/integration tests run on the HPOS wp-env, but
a test that only checks "export returns *something*" wouldn't notice that the
order-meta section was empty; and the GDPR live-walk could have missed it too
(the engine side would pass). The static analyzer caught it *before* any runtime
test — because the type mismatch was a symptom of the storage assumption.

**Rules for next time:**
- Treat a PHPStan complaint as a question ("why don't these types agree?"), not a
  nuisance to cast away. The cast that doesn't typecheck is often a storage /
  shape assumption that's wrong.
- For ORDER data, prefer the WooCommerce object API over WP core post/meta
  functions: `$order->get_meta()` not `get_post_meta()`, `wc_get_orders()` not
  `WP_Query( post_type=shop_order )`. Post/meta functions are legacy-storage-only;
  the WC API is HPOS-safe. (Sibling to 3.5.2: read meta via the WC API, but
  ENUMERATE with direct SQL when you need a stable id-cursor `wc_get_orders`
  can't give — pick the storage-correct tool for each job.)

---

### 2.9 A URL placeholder convention is a wire shape — the live-walk caught it, the mock hid it

**What happened** (3.8.1 GDPR live-walk): the GDPR customer endpoints carry the
email in the URL **path**. The engine's endpoints-map advertises them with a
literal `{email}` token: `…/customer/{email}/export`. The plugin's `Client`
built the URL with `sprintf( resolve_url(…), rawurlencode($email) )` — i.e. it
assumed a `%s` placeholder. `sprintf` on a string that contains `{email}` (and
no `%s`) returns it **unchanged**, so every GDPR request went to the literal
`…/customer/{email}/export`. The engine received the literal string `{email}`
as the email and answered `No customer with email '{email}'` — a 404 that looked
like an engine bug (the un-interpolated `{email}` in the message reinforced the
illusion). A raw request to the *same* URL with the email substituted returned
`200`, proving the fault was entirely plugin-side.

**Why every test was green anyway:** the unit ClientTest seeded its endpoints map
with `…/customer/%s/export`, and the integration mock's endpoints map did too.
Both mirrored the plugin's *wrong* assumption, so `sprintf` substituted happily
and the assertions passed. The live engine is the only place that used `{email}`
— so only the live-walk could surface it. This is §2.3/§2.4 again, in a new
costume: **the placeholder syntax in an endpoints-map value is itself a wire
shape**, and a mock built to the same assumption as the code validates the bug
instead of catching it.

The fix: substitute via `str_replace( '{email}', rawurlencode($email), $url )`,
not `sprintf` — unifying on the engine's `{email}` convention (fallback path
templates now use `{email}` too). The mock + unit map were switched to `{email}`
to mirror live, and the mock's customer routes now **422 on a literal-placeholder
email**, so a future `sprintf`-style regression fails the integration suite loudly.

**Rules for next time:**
- When a URL comes from the engine's endpoints-map, **the placeholder syntax is
  part of the contract** — confirm it (`{name}` vs `%s` vs `:name`) before
  choosing a substitution function. `sprintf` silently no-ops on a non-`%`
  template; `str_replace` silently no-ops on a missing token. Either can ship a
  literal placeholder to the wire.
- Seed unit/mock endpoints maps with the **engine's real placeholder form**, not
  a form convenient for your substitution code. A mock map that matches your code
  proves nothing.
- A 404 whose message echoes an un-interpolated placeholder (`'{email}'`,
  `':id'`) is almost always *your* request sending the literal token — look at
  the outgoing URL before blaming the engine.
- 3.8.0 shipped (committed) with this latent bug and full green gates; 3.8.1's
  live-walk is what caught it. A formatted-field feature is not done until a
  live-walk has exercised it against the real engine (CLAUDE.md scar).

---

### 2.10 A "future-looking" version may be REAL — verify before downgrading it (knowledge-cutoff artefact)

**What happened** (P5 version-floor reconciliation, two separate agents): the
README requirements table listed `WordPress … tested up to 7.0`. The P5 "reconcile
to one floor" pass treated WP 7.0 as a future/typo value and **downgraded it to
6.9** — alongside a genuinely-correct fix (WooCommerce min `10.0 → 6.9`, since 10.0
was an impossible floor for the WC-6.9.4 pilot). The reviewing agent then compounded
it: in the next message it asserted "WP 7.0 doesn't exist" *before* checking. **Both
were wrong** — WordPress 7.0 "Armstrong" shipped **2026-05-20**, the plugin team
wrote a whole `docs/WP7_COMPAT.md` about supporting it, and the original README value
was correct. The evidence was sitting in the repo the entire time.

**Why two agents made the same mistake — it's systemic, not a one-off:**
- **Knowledge cutoff.** Both agents' training cutoff predates WP 7.0 (May 2026), so
  a 7.0 version *looks* non-existent. That's a **cutoff artefact, not a fact about
  the world.** A version number later than your cutoff is exactly the case where
  your prior is least trustworthy.
- **Context-dimming + a true sibling.** WC `10.0` in the same table WAS a real error
  (an impossible minimum for the pilot), correctly downgraded. WP `7.0` *looked the
  same* (a suspiciously-high number) but had the **opposite truth**. Same shape,
  different reality — pattern-matching off the WC case dragged WP down with it.

**Rules for next time:**
- **Before LOWERING a future-looking version value, verify it** — check the repo's
  own compatibility docs (`WP7_COMPAT.md`), the file's git history (was it
  deliberately set?), or web-search. Don't downgrade on a "that looks like a typo"
  hunch. Lowering a real, supported version silently drops support.
- **Treat any version newer than your knowledge cutoff as "unknown, verify",** not
  "doesn't exist." The cutoff is the boundary of *your* knowledge, not of reality.
- **"Same-looking" ≠ "same truth."** WC 10.0 (real error → downgrade) and WP 7.0
  (real version → keep) were visually identical and oppositely correct. The check —
  not the resemblance — is what tells them apart. A reconcile-to-one-truth pass must
  verify each value against a source, not infer one value's fate from another's.

### 2.11 Silent pre-enqueue drops are invisible to every monitor you built (pilot, F3-36)

The first real pilot store had **no SKUs on any product**. Three surfaces
degraded, but only ONE was visible: orders D6-failed loudly in the Event Log
(50 red rows), while the catalog — the surface that mattered most — was
**silently empty**: SKU-less units were dropped *before enqueue*, so there was
no queue row, no failure, no Event Log trace, nothing for health notices to
count. The engine simply never saw the store, and every dashboard said "fine".

**The general lesson:** a validity filter placed BEFORE the queue turns "can't
ingest" into "never happened." Everything downstream of the queue is
observable (rows, statuses, retries, Event Log, notices); everything upstream
is invisible. So:

- **Don't pre-filter what the pipeline can make observable.** If a record
  can't be sent, let it reach a state the Event Log can show (or make it
  sendable — here, synthetic keys). A drop that leaves no trace will be
  diagnosed from the OUTSIDE (the merchant asking "where are my products?"),
  at pilot time, by a human.
- **"Engine requires X" ≠ "skip records without X".** The requirement was
  real (sku is the engine's key); the response to it was wrong. The right
  question is "how do we SUPPLY X for every record?" (SkuResolver synthesises
  it), not "which records do we silently exclude?".
- **Test-store defaults hide this class.** Every dev/test product got a SKU
  out of habit (`make_product('ORD-SKU-1', …)`), so no gate — unit,
  integration, or live-walk — ever exercised a SKU-less store. The store
  configuration matrix (SKUs: none/partial/all; HPOS/legacy; guest-only) is
  test input, same as the data matrix.
- **A loud failure next to a silent one is a gift:** the 50 failed orders were
  the only reason the catalog gap was found on day one. When one surface
  fails on a data-shape assumption, immediately audit every OTHER surface
  sharing that assumption for the silent version of the same failure.

### 2.12 An incremental hook must inherit the backfill's eligibility filter (catalog.delete auto-draft burst)

The catalog **backfill** enumerates `post_status = 'publish'` products only
(`CatalogBackfillJob`). The incremental **hooks** (`CatalogHookHandler` on
`save_post_product` / `before_delete_post`) inherited no such filter — they fired
for a product post of *any* status. WordPress's daily auto-draft GC
(`wp_scheduled_auto_draft_delete`) deletes piles of `AUTO-DRAFT` product posts at
once; each fired `before_delete_post` → `catalog.delete` carrying an object with an
empty `category_path` (auto-drafts have no category) and sometimes empty
`product_url`. The engine has no delete-by-key — removal is an UPSERT with
`in_stock=false` that must pass `ProductSchema` (both fields REQUIRED non-empty) —
so every one became a permanently-failed `d6_item_error` row. A burst of identical
red rows in the pilot's Event Log (2026-06-14, all within one second) was the tell.

**The general lesson:** when a system has a one-time **backfill** and a live
**incremental** path feeding the same sink, the incremental path must apply the
**same eligibility filter** as the backfill — otherwise it emits records the
backfill would never have produced, and the sink rejects them. The asymmetry is
easy to miss because the backfill's filter is an explicit `WHERE post_status =
'publish'` while the hook's "filter" is the *absence* of one.

- **A removal of a never-ingested record is a no-op, not an error.** The artifact
  was never sent (backfill is publish-only), so there is nothing to remove. The
  fix skips it *before enqueue* — which looks like a §2.11 silent pre-enqueue drop
  but is the opposite case: §2.11 warns against dropping records that **should** be
  sent and would otherwise vanish without trace; here the record **cannot** and
  **should not** be sent (a non-product the engine is contract-guaranteed to 400),
  and the existing `on_delete_product` already silently returns for non-product
  post types. The distinguishing test is "would the pipeline ever have ingested
  this?" — if no, a pre-enqueue skip loses no signal.
- **Don't generalise the skip to the upsert path.** An empty `category_path` on a
  *published* product is a real merchant-data gap the engine *should* surface as a
  failed row (`CatalogPayloadBuilder::primary_category_path()` documents this
  intent). A blanket wire-level guard would have suppressed that signal. The guard
  belongs only where the record is provably non-ingestable — the delete of a
  never-published artifact, not the upsert of a real-but-incomplete one.

### 2.13 A WooCommerce event with two code paths (classic + block) needs BOTH — verify the pilot's actual config before a scope-cut

**What happened (F3-46 → MiuMjau, 2026-06-30).** Rec-attribution stamping (read the
`smaily_rec` cookie → write order meta → send to the engine) was hooked only on
`woocommerce_checkout_order_processed`, which the **classic** checkout fires. F3-46
explicitly deferred the **block-checkout** path as a documented scope-cut: *"NOT
built … block-checkout (`woocommerce_store_api_checkout_order_processed`) stamping
gap (classic checkout only, as today)."* It looked safe — "as today" matched the
prior behaviour. But the pilot store (MiuMjau) **runs block checkout**, so for
weeks the cookie was captured yet **never reached the order**: `is_connected=true`,
`order.upsert` returned HTTP 200, the engine accepted every order — but with **zero
attribution fields**. Engine-side this read as "0 orders carry `smaily_rec_id`,"
and a High-severity field regression sat invisible until someone cross-checked the
order payload against the click data.

**Why it slipped.** The scope-cut was made on an **unverified assumption** — that
the pilot used classic checkout. Nobody confirmed it. The fix (a one-line Store-API
twin hook) was trivial; the gap's cost (a silent, weeks-long attribution loss that
made the whole rec-engine look unmeasurable) was not.

**The lesson — three parts.**
1. **Two code paths for the same outcome → cover both, unless you've VERIFIED only
   one is in use.** Classic vs block checkout, REST vs admin order creation, WPML
   single-domain vs domain-per-language — when a WC/WP capability has a forked
   implementation, a hook on one fork silently misses the other. "As today" is not
   a justification when "today" was already incomplete.
2. **Verify the pilot's actual configuration before scoping against it.** "The
   pilot uses X" is a fact to check (one screenshot of their checkout, their WPML
   mode, their plugin version), not to assume. The whole block-checkout miss was an
   unchecked assumption about one setting.
3. **A capture that can silently bail is a monitoring gap, not just a code gap.**
   The bail had no trace — `LandingCapture` returned early on a closed gate / wrong
   path and logged nothing, so the failure was invisible to every dashboard. The
   fix added WP_DEBUG bail-reason logging (captured / not-connected / headers-sent /
   no-valid-param) so the next silent miss is one log line away, not a field-data
   forensics exercise. (Generalises LESSONS §2.11: a silent drop is invisible to
   every monitor you built.)

Companion: `docs/EDGE_CASES_REC_ATTRIBUTION_CONTACT_SYNC.md` (the full order-path +
capture-gate sweep this triggered); DECISIONS F3-46 (the gap, now fixed `e55514d`).

**This class has now recurred twice more, each time as a LEFTOVER of the previous
fix** — PRO-1518 (order confirmation, 2026-07-22) and PRO-1679 (the first-order
automation, 2026-08-04). Both were carried across one behaviour at a time, so each fix
left the NEXT classic-only behaviour behind. **Fourth part of the lesson: when you add
the Store-API twin for one behaviour, sweep every OTHER callback on the classic hook in
the same pass** — `grep -n "woocommerce_checkout_order_processed"` across the plugin and
ask, per registration, whether the block checkout needs it too. A per-behaviour carry is
how a known bug class survives three separate fixes.

---

### 2.14 A live-walk failure can be the TEST's fault, not the code's — isolate one variable before "fixing" the implementation

**What happened (F3-48 Smaily contact-API live-walk, 2026-07-01).** The walk drove
the real `Smaily\Connect\Smaily\Client` against the `smailydemo` sandbox. The contact
upsert came back `{"code":203}` ("invalid data"); the test contact never landed, so
every downstream consent read failed too. The plausible story wrote itself: the
Smaily docs show contact.php with `Content-Type: application/json` + a JSON body, but
the Client does `wp_remote_post($url, ['body' => $array])` — which WordPress
**form-encodes** (`0[email]=…`). "Found it: the Client should send JSON." I was one
edit from converting the Client.

**Why that was wrong.** The synthetic test address was `…@example.test`. Erkki asked
the two questions that mattered: *"could it be the `.test` domain?"* and *"isn't that
in the gotcha doc?"* A controlled probe that varied **one axis at a time** — 4 body
encodings (JSON object, JSON array, form-flat, form-batch) × 3 email domains
(`.test`, `example.com`, `mailinator.com`) — settled it in one run:

- `…@example.test` → **203 in every encoding, including correct JSON**;
- `…@example.com` / `…@mailinator.com` → **101 in every encoding, including the
  Client's exact form-encoded batch**.

So the Client's wire format was **correct all along**; live Smaily rejects an
RFC-6761 **reserved-TLD** address (`.test`/`.example`/`.invalid`/`.localhost`) with
`203`, not the email-syntax code `204`. Re-running the walk with `@example.com` →
all 12 checks green. (Bonus divergence the probe also exposed: contact.php is
**async** — an immediate readback after a `101` upsert returns `206` "not found"; the
walk had to **poll**, not single-read.)

**The lesson — four parts.**
1. **When a live-walk fails, first decide whether the fault is in the code-under-test
   or the test fixture.** A failing live assertion is evidence of *a* divergence, not
   evidence of *which* one. I conflated two variables (a wrong email domain + a
   plausible-but-wrong wire-format theory) and almost "fixed" working code.
2. **Isolate by varying ONE axis at a time.** The 4×3 format×domain probe is the
   whole technique: hold the body constant and change the domain, hold the domain
   constant and change the body. The cell that fails across *every* value of the other
   axis is your culprit. This is cheaper than reading the implementation and guessing.
3. **A reserved-TLD email is a live-only landmine the mock can't show you.** The
   integration mock validated loosely and never checks TLDs, so it accepted `.test`
   happily — the rejection exists only on the live Zod/validator. Use a real-but-
   non-delivering domain (`@example.com`, RFC-2606) for synthetic live contacts; never
   `.test`/`.example`/`.invalid`. (Now documented: `re/docs/smaily-api/guides/gotchas.md`
   → "Reserved-TLD emails".)
4. **The domain expert's offhand question beat the plausible code theory.** "Could it
   be the domain?" cost one sentence; chasing the JSON theory would have shipped a
   needless Client rewrite and *still* failed (JSON `.test` is also 203). When a fix
   feels obvious from the code, a 30-second controlled probe is cheaper than being
   confidently wrong.

Companion: `bin/walk-f3-48-contact-sync.cjs` (the walk, with the sandbox gate +
`@example.com` + async poll baked in); the gotcha lives in
`re/docs/smaily-api/guides/gotchas.md`.

---

### 2.15 A fail-closed integration gate needs its dependency LIVE-PROBED, not assumed present (browse 0-events, MiuMjau, 2026-07-03)

**What happened.** MiuMjau had browse-tracking enabled and the engine connection was
healthy (ping/orders/catalog all flowing), but the engine received **0** requests on
`/api/v1/ingest/browse`. The browse beacon gates **fail-closed** on the WP Consent API:
`beacon-core.ts` `detectConsent()` returns true only when `window.wp_has_consent('marketing')
=== true`, else false, and `init()` won't fire even the first page-view without it. Our
own docs asserted "MiuMjau (CookieYes) is WP-Consent-API-native, doesn't need a consent
bridge" — so the gate was assumed open. One line in the storefront console settled it:
`typeof window.wp_has_consent` → **`'undefined'`**. CookieYes was present (its own
`log.cookieyes.com` beacon fired) but it does **not** expose the WP Consent API global, so
the gate was closed for every visitor. Zero events, despite users accepting the banner.

**Why the assumption survived so long.** Browse browser-timing is explicitly NOT
live-walk-covered (the server-side walk proves proxy→engine, never the browser render
moment — CLAUDE.md, STATUS). So the one gap the walk can't see — *does consent ever
resolve true in a real browser on the pilot's actual CMP?* — is exactly where an unproven
assumption hid. The `wp_has_consent`-native claim was written once, never probed against
MiuMjau, and propagated into DECISIONS + STATUS as if established.

**The twist — the "fix" was also an assumption, and it was wrong too.** Live-probing gave
the SYMPTOM (`wp_has_consent` undefined) but I then assumed the CAUSE ("CookieYes can't do
the WP Consent API") and shipped a CookieYes-specific cookie-parser (3.3.1). Erkki challenged
it: (a) per-vendor consent code is exactly the maintenance treadmill we standardised on the
WP Consent API to avoid, and (b) CookieYes's own docs say it DOES integrate the WP Consent
API. Both right. **Real cause:** CookieYes integrates the API, but only when the free
companion **"WP Consent API" plugin** (`wp-consent-api`) is installed — that plugin defines
`window.wp_has_consent`; CookieYes registers into it. MiuMjau just lacked the companion
plugin. The standard already covered CookieYes; the fix was a config install + an admin
advisory, and the vendor code was **reverted** (3.3.2, F3-50).

**The lesson — four parts.**
1. **A fail-closed gate turns a missing/mismatched dependency into total silence, not a
   degraded signal.** "0 events" reads identical to "feature off" — no error, no log, no
   partial data. When a whole telemetry stream is empty, suspect the *gate's dependency*
   (is the consent global even defined?) before the transport. Absence of an error is not
   evidence the gate is open.
2. **Live-probe the ACTUAL client stack — but a probe reveals the symptom, not the cause.**
   `typeof window.wp_has_consent === 'undefined'` was the 5-second win that beat doc-
   confidence (§2.3, §2.7). But then read the vendor's OWN docs before building around the
   symptom: "the global is missing" had two causes — "the vendor can't provide it" (my
   assumption) vs "a required companion plugin isn't installed" (the truth). I built for the
   first without checking the second.
3. **Before writing a per-vendor adapter, check whether the STANDARD already covers the
   vendor with configuration.** Reaching for bespoke integration code is a strong signal to
   stop and re-read the standard + the vendor's integration docs. A config fix (install a
   plugin, flip a setting) beats code you then own forever. The maintainable move here was
   an admin advisory that guides the merchant to the standard, not a vendor cookie-parser.
4. **When a coverage boundary is documented ("manual pilot check"), it's a STANDING todo.**
   The consent-resolution moment lived inside the documented "browse browser-timing not
   live-walk-covered" gap and went unchecked until the pilot showed 0 events. A recorded
   "manual check needed" should be scheduled, not just filed.

Resolution: F3-50 — revert the vendor code, keep browse consent on the WP Consent API, add a
`NotificationManager` advisory (browse on + connected + no `wp_has_consent` → "install the free
WP Consent API plugin"). MiuMjau's fix is installing that companion plugin (wp-admin, no file
access). Also corrected alongside: there is **no** `smaily_connect_beacon_consent` PHP filter
(only the JS `consentOverride` + `smaily_connect_beacon_consent_category`) — an earlier note
claimed one.

### 2.16 HPOS order cleanup: `wp_delete_post()` is a silent no-op — and residue can hide from registered-status sweeps (OrderBackfill full-suite flake, 2026-07-07)

**What happened:** the full integration suite failed 3 `RecEngineOrderBackfillTest`
count asserts, every order count +1. First read: cross-test state leaked by a
recently-added test. The truth (found by dumping raw `wc_orders` rows at setUp and
assert time): a **single order created 2026-06-19 by the F3-43 LIVE-WALK**
(`bin/walk-f3-43-orders.cjs`, status `wc-label-printed`, email `f343-custom@…`) had
sat in the dev wp-env DB for 18 days. Two independent bugs kept it alive AND
invisible:

1. **The walk's cleanup used `wp_delete_post( $order_id, true )` — a silent NO-OP
   under HPOS** (orders live in `wc_orders`, not `wp_posts`; nothing errors, nothing
   is deleted). `RecEngineOrdersTest::tearDown()` had the same bug — its leaked
   orders just happened to have *registered* statuses, so the next run's sweep
   caught them.
2. **The test's `delete_all_orders()` swept via `wc_get_orders( status:
   wc_get_order_statuses() )` — blind to a custom status that is no longer
   registered.** The walk registers `wc-label-printed` only inside its own process;
   in every later process the sweep can't match the row, while the backfill's F3-42
   denylist SQL (`status NOT IN (non-sale)`) counts exactly such a row as a sale.
   So the residue was invisible to the cleanup and visible to the code under test —
   the worst combination.

**Why it looked like a flake:** "isolation green, full-suite red" reports mixed runs
against differing env state; once probed with raw-SQL dumps, the failure was fully
deterministic (red in isolation too). Don't accept a flake-shaped description
without dumping the actual table at assert time — one `$wpdb->get_results` told the
whole story in one run.

**Rules for next time:**
1. **Delete orders via `wc_get_order( $id )->delete( true )`, never
   `wp_delete_post()`** — in tests, walks, any wp-env script. `wp_delete_post` on an
   HPOS order fails silently (generalises §2.8: storage-mode assumptions hide in
   cleanup code too, where no assert ever looks).
2. **A test that asserts exact counts over a whole table must sweep that table
   status-blind** — enumerate raw ids from the same table the code under test
   queries (`OrderBackfillJob::table_spec()`), not through a registered-status
   filter that can't see what the query under test can.
3. **Live-walks share the integration DB — their cleanup is suite infrastructure.**
   A walk that leaks doesn't fail; it poisons a test days later (§2.6's sibling:
   state you *want* gone can also persist).

### 2.17 Engine-side state is TENANT-scoped — a walk's residue shows up in every store on that tenant (automations config, 2026-07-07)

The T2.3 live-walk PUT an automations-config row (`replenish_due`,
`language_mode='single'`) to the "Smaily Connect test" sandbox tenant and
cleaned up only the fail-closed part (`enabled=false`) — the row itself stayed,
by design (PUT never deletes). Hours later Erkki's REAL test store, connected
to the SAME sandbox tenant, rendered that row's `single` mode as one dropdown
while every other trigger got the store-derived two-dropdowns-plus-fallback —
it looked like a UI bug (and exposed one: T2.4 made the display mode
store-global).

The generalisation, one level above §2.16's "walks share the integration DB":

1. **Engine-side writes are shared per TENANT, not per store/connection.** Any
   store (or dev wp-env) on the same tenant sees — and overwrites — the same
   automations configs, catalog state, contacts. A walk against the sandbox is
   never isolated from other sandbox-connected stores.
2. **"Cleanup" for engine state means restoring the SEMANTIC baseline, not just
   the safety property.** `enabled=false` kept it fail-closed (right priority),
   but the leftover row still changed what another store's UI showed. When a
   walk writes engine state that can't be deleted, the report must SAY what
   residue remains.
3. **When a pilot/store reports a weird per-item inconsistency, ask first:
   did a walk or another store on this tenant write that state?** It reproduces
   only on that tenant — a fresh tenant looks fine.

**Mechanical guard (2026-07-11, PRO-1240):** the plugin-side half of this
lesson's failure family — an integration-suite run overwriting the DEV site's
`smly_rec_*` options with fixture values (F3-53, and again found fixture-dead
with no snapshot on 2026-07-10) — is no longer discipline-based.
`bin/run-integration-tests.sh` now snapshots the dev site's `smly_rec_*`
options (durable, mode-600, outside the repo, never clobbering a good
snapshot with a fixture state) before every suite run and restores + verifies
`tenant_name` after it, even when the suite fails. Since PRO-1256 the guard
is a shared library (`bin/lib-smly-snapshot.sh`; Node wrapper
`bin/lib-smly-snapshot.cjs`): a connection-writing walk calls
`guardSmlyRec()` at its top (wired example: `bin/walk-3.1.cjs`, the only walk
that writes `smly_rec_*` options — the rest only read the connection or
truncate the queue table), and
`bash bin/run-integration-tests.sh --restore-only` recovers the dev
connection from the durable snapshot after any run that bypassed the guard.
The manual snapshot/restore rule survives only for hand-rolled runs that use
neither. The ENGINE-side half (tenant-scoped walk residue) remains
discipline: a walk's report must still name the residue it leaves.

### 2.18 An "orphaned" legacy callback isn't dead while a legacy scheduler can still fire it — and one poison row must never own the whole pass (Prike, 2026-07-08)

Prike installed the new module over the old one (no in-place upgrade). Result,
starting minutes after their setup wizard: a PHP 8 fatal
(`Cannot access offset of type string on string`) every 15 minutes on the
abandoned-cart tick, plus the legacy WP-Cron events alive in the cron option.

Three lessons, each a general class:

1. **Foreign-written rows are wire input, not trusted state.** The legacy
   email pass assumed `cart_content` deserializes to the exact array shape
   OUR `cart.class.php` serializes. Rows surviving a module swap (older/
   foreign plugin version) deserialized to an array of bare STRINGS —
   `$cart_item['product_id']` on a string is a fatal on PHP 8 (a silent
   notice on PHP 7, which is why the code never looked broken). Any reader
   of persisted data another writer could have produced needs shape guards,
   same as an API payload.
2. **A poison row must never own the whole pass.** The failing cart stayed
   `mail_sent NULL`, the fatal aborted the tick before the per-cart
   `continue` (F3-37 covered API errors, not Throwables), so the SAME row
   re-fataled every 15 minutes forever — and blocked every healthy cart
   behind it. The fix mirrors the D6 philosophy: guard + per-item skip +
   per-cart Throwable backstop that terminal-marks (observable in the log),
   never an eternal silent retry loop.
3. **"Orphaned" ≠ dead.** F3-48.3 stopped the AS tick from bridging to the
   legacy `smaily_sync_subscribers` mass-send and called the still-registered
   callback "dead, harmless." It wasn't: the legacy `Lifecycle` re-scheduled
   the WP-Cron events from `activate()`/`activated_plugin` (any WooCommerce
   re-activation) AFTER WPCronAuditor's one-time activation clear — and a
   surviving `smaily_connect_cron_sync_subscribers` event fires that callback
   daily, resurrecting the exact F3-47 language clobber, on the exact store
   it originally hit. A callback you've decided must never run again gets its
   `add_action` removed; a scheduler you've migrated off gets its re-arm code
   removed — not just its events cleared once. (F3-53.)

### 2.19 A test fixture that seeds state ITSELF hides the writer↔reader seam — and a client's error line number beats your whole theory (Prike, 2026-07-08)

Follow-up to §2.18, same incident, deeper root cause. The F3-53 diagnosis
(poison cart rows) was built from the symptom description; the client dev's
correction — "the fatal is at cron.class.php:166, the empty-option guard, and
turning it off in admin doesn't help" — invalidated it in one line. The real
bug: the new Settings wrote the abandoned-cart status option as a bare boolean
while the legacy cron read it as an array (`$status['enabled']` on WP's stored
`'1'`/`''` string = PHP 8 TypeError, every 15 minutes; toggling off just wrote
the other string).

1. **A fixture that seeds shared state itself proves nothing about the seam.**
   `AbandonedCartGuardTest` wrote the option in setUp — in the ARRAY shape the
   READER expects — so writer-shape drift was structurally invisible, exactly
   like a mock built to your own assumption (§2.4). Any state with more than
   one owner (an option a REST endpoint writes and a cron job reads) needs at
   least one test that drives the REAL writer and the REAL reader in the same
   scenario (`AbandonedCartSettingsSeamTest`). The existing round-trip test
   (§2.H.19) checked writer↔HYDRATE, but the cron pass was a third, unchecked
   reader with a different shape expectation.
2. **When a bug report names an exact line, re-anchor the diagnosis there
   before shipping.** The F3-53 hardening was correct defense-in-depth but
   built one loop too deep — the reported fatal never reached the loop. Ask
   for the stack line FIRST; it's the cheapest piece of ground truth a client
   can give.
3. **`(bool)`-casting an option that might be an array is its own bug class.**
   `(bool) array('enabled' => false)` is TRUE — the wizard hydrate read
   "disabled" as "enabled". A multi-shape option gets ONE normalizer, and
   every consumer goes through it (F3-54).

### 2.20 A field's wire shape comes from the shipped reference + the contract example — NOT an issue's prose (PRO-1224 `tags.product_id`, 2026-07-10)

Implementing the Woo canonical-key work (PRO-1224), the new `tags.product_id`
field had **two conflicting descriptions**: the tracking issue PRO-1230 said emit
`"woo-<product_id>"` (namespaced), while the sibling Shopify code (shipped, the
parity reference) emitted `tags.product_id = product.id` — the RAW parent id — and
the contract's own §3b example was `product_ids: ["7620134"]` (raw, not
`"shp-7620134"`). Trusting the issue prose would have made Woo emit a `woo-`-prefixed
group id that **doesn't match Shopify**, so the engine's cross-plugin grouping and
its remove-by-`product_id` matching would silently diverge per source — a bug the
mock (built to whatever I assumed) would happily hide.

**Lessons:**
1. **A tracking issue's field description is intent, not the wire contract.** When
   an issue and the shipped reference/contract disagree on a shape, the running
   code + the contract example win. I read `catalog-builder.server.ts` and the §3b
   JSON before choosing `raw` — that's what caught it. (Generalizes §2.7: a sync
   isn't code-complete; here, an *issue* isn't the wire truth either.)
2. **Parity fields must be verified against the OTHER implementation, not derived
   independently.** Two plugins feeding one engine field is a cross-repo contract;
   diverging by a prefix is exactly the kind of drift that reads fine in isolation
   and breaks only when the engine joins both.
3. **Changing the wire meaning of a key breaks every mock trigger keyed on it.**
   The catalog mock forced its retry/revoke/D6 scenarios by prefix-matching the
   `sku` (`AUTH-401`, `D6ERR`). Once `sku` became the un-controllable `woo-<id>`,
   those triggers silently stopped firing — one test even PASSED anyway (a revoke
   that no longer happened still returned `ok`). Moving the triggers to `event_id`
   (which the test controls) restored them. When you change what a field carries,
   grep the mock for every trigger keyed on it.

### 2.21 A test that seeds a NON-DEFAULT config never tests the fresh install — and a setting no UI writes is a dead switch (PRO-1680 abandoned-cart product details, 2026-08-04)

Abandoned-cart reminders went out with an empty `product_<field>_1..10` matrix on
every fresh install: `CartPayloadBuilder` gated the product slots on the
`smaily_connect_abandoned_cart_fields` option, whose `product_*` keys all default
to FALSE — and **nothing in the plugin ever writes that option**. It survives only
as an upgrade artefact from a version that had the selector, so a store's reminder
had products iff it was old enough. Every gate was green throughout.

1. **Seeding a non-default value in `setUp()` tests the config you invented, not
   the one merchants get.** `CartPipelineTest` seeded the fields option with
   `product_name`/`product_quantity` enabled to have something to assert — which
   is precisely the configuration that hid the defect. When a behaviour depends on
   an option, at least one case must run on the **shipped default**; seed a
   non-default only to pin the non-default branch, and say so in the test.
   (Sibling of §2.19: there the fixture hid a writer↔reader shape seam, here it
   hid the default's behaviour.)
2. **An option with a reader and no writer is a defect, not a feature.** Grep both
   directions before trusting a setting: `get_option` gave three call sites,
   `update_option` gave none. A read-only option silently freezes behaviour at
   whatever history left behind — and it is invisible on any fresh install, so no
   amount of dev-environment testing surfaces it.
3. **"Configurable" is not automatically the safer answer.** The fix was to DELETE
   the choice (products are always sent) rather than build the missing UI: the
   downstream Smaily template already decides what to render, so a second switch
   upstream only adds a way to be wrong. Ask whether the consumer already has the
   control before adding one.
4. **When empty values are load-bearing, say so where someone might "clean them
   up".** The unused slots ride the wire as `''` on purpose — the Smaily contact
   keeps whatever the last send wrote, so overwriting all 10 slots is what clears
   the PREVIOUS cart. An "optimisation" that omits empty fields would silently
   resurrect the old cart in the next reminder. It is now pinned by a two-cart
   integration case, not just a comment.
5. **Retiring a dead switch means auditing EVERY field it gated, not the one that
   was reported.** The same option also gated `first_name`/`last_name`, also
   defaulting to FALSE — so the fresh install that had just been fixed to send its
   products still sent no NAME, and a template's first-name merge tag still
   rendered nothing. It was found and fixed separately a day later (PRO-1729).
   The fix scope should have been "which keys of this dead option are read", not
   "the keys in the bug report" — a single `grep` of the option's consumers
   answers it.
6. **"Always send" is not one uniform rule — ask what the field means to the
   destination.** A `product_*` slot is CART state: sending it empty is the
   mechanism that clears the previous cart. A name is CONTACT state, where the
   F3-47 omit rule applies: absent preserves, empty WIPES. Copying the products'
   "fill everything unconditionally" shape onto the names would have made every
   nameless shopper's reminder erase a name the contact already had. When you
   generalise a fix across fields, re-derive the empty-vs-omit answer per field.

### 2.22 A gate that reads a key nothing writes always answers with its default — and a boolean OFF can't be stored with `update_option()` (PRO-1742 contact-sync switch, 2026-08-04)

The "Sync contacts to Smaily" switch was written as
`smaily_connect_subscriber_sync_enabled` and read as
`smly_plus_subscriber_sync_enabled` — a key no version of this plugin has ever
written. Both default to ON, so every store looked correct; only a merchant who
switched the sync OFF was affected, and they got the opposite of what they asked
for. Third settings-key drift found in one day (PRO-1683, PRO-1684, this).

1. **Grep BOTH directions for every option, and let the writer win.** §2.21's
   read-only option froze behaviour at history; this is the mirror — a reader with
   no writer is permanently pinned to its default, which is invisible whenever the
   default is what you'd expect anyway. The canonical key is the one the UI
   actually writes; make every surface read it through **one accessor** rather
   than repeating the string (the PRO-1683/1684 remedy, applied a third time).
2. **A default-ON boolean can't be turned off by `update_option()` alone.** WP
   compares the new value with `get_option( $option )`, which returns `false` for
   an option that has never been saved — so `update_option( $key, false )` decides
   nothing changed and writes NOTHING. With a default of ON that silently
   discarded the merchant's "off" on a fresh store. Store such a flag as `'1'` /
   `''` (WP's own on-disk shape for booleans), or add the row explicitly. Flags
   that default to OFF hide this perfectly — which is why it survives review.
3. **A default that is safe on a fresh install can be unsafe on an upgrade.**
   "Never configured" and "explicitly switched off" are the same absent option;
   decide which one an absence means, write it down, and make sure something
   asks the merchant again when it matters.

### 2.23 A permissive reader in front of a strict validator loses data quietly — validate where the value ENTERS and where it LEAVES (PRO-1710 `smaily_rec_id`, 2026-08-04)

The rec-id landing capture accepted any bounded id token on purpose ("don't
hard-fail if the engine's rec_id shape ever changes"), while the engine
validated the same value as a UUID **per order**. Result: a shopper landing with
a junk `?smaily_rec=` got it cookied, it rode their order to the engine, and
that ONE order was rejected permanently — an actual purchase lost, from a URL
param anyone can type. Every gate was green because the mock never inspected the
field either (§2.3/§2.4 again — the mock validated our assumption, not reality).

1. **Being lenient about a value you don't own is not defensive; it's deferring
   someone else's rejection to your most expensive moment.** A landing param
   costs nothing to discard (no attribution). The same value at checkout costs
   the whole order. If a field flows from an untrusted edge to a strict
   validator, the edge is where you enforce the shape.
2. **A fix at the entry point cannot reach values already stored.** Cookies live
   in browsers, rows live in queues — a release can't rewrite them. Anything
   already captured under the old rule keeps arriving, so a second guard at the
   SEND point (drop the field, keep the record) is part of the same fix, not
   gold-plating. Same shape as PRO-1498→PRO-1506 (enqueue-time repair that had
   to be repeated at flush time).
3. **When the contract document and the engine's code disagree about a type, the
   code is the authority.** §5 typed this field as a plain `string`; the route
   typed it `z.string().uuid()`. Ask for the doc to be corrected (PRO-1713), but
   validate against what the deployed route actually enforces — and mirror that
   constraint in the mock in the same pass, or the next regression hides exactly
   as long.

### 2.24 When the receiver REPLACES rather than merges, a field must be derived at send time — carrying it on the event silently erases it later (PRO-1633 return signals, 2026-08-05)

The engine replaces an order's items wholesale on every re-ingest. So a return
flag we sent once is erased by the next sync of that order that doesn't repeat
it — and nothing anywhere reports the erasure: no error, no `errors[]` entry,
just a signal quietly reverting to "kept". The obvious implementation (the
refund event carries the returned lines into the queue row) would have worked
perfectly on the day of the refund and been wrong on the next status change.

1. **Ask "does the receiver merge or replace?" before deciding where a field
   comes from.** On a REPLACE contract, every send must be a complete statement
   of current truth, so each field has to be re-derivable from durable state at
   send time. Anything derived from the triggering EVENT is correct exactly
   once. (This is why our queue rows have carried empty payloads since F3-36 —
   the same instinct, now with a second reason behind it.)
2. **Derive-at-send makes the retry, the backfill and the "we turned it on
   late" case free.** No historical backfill of refunds was written, because
   any order re-synced for any reason re-reads its refunds and carries them.
   The feature became "one hook + one derivation" instead of "one hook + a
   store of what we sent + a reconciliation pass".
3. **Test the ERASURE, not just the write.** The regression this class of bug
   produces looks like a passing test: the first send is correct. The
   integration test therefore drives a LATER, unrelated sync of the same order
   and asserts the field is still there — that assertion is the whole point.

### 2.25 An integration test that borrows another test's side effects fails by SUITE ORDER — and suite order is filesystem order (PRO-1943, 2026-08-10)

Two harness bugs found together, both of the same shape: a test relying on
state it did not arrange itself.

1. **A per-key option cache the scrub forgot is worse than a stale value — it
   makes the option unwritable.** `EnvScrub` LIKE-deletes the `smly_%` rows but
   flushes per-key caches only for a named list. For an `autoload=false` option
   left off that list, `get_option()` keeps serving the pre-scrub value AND
   `update_option()` to a new value finds no row to UPDATE (0 rows affected),
   writes nothing and returns false — leaving the cache intact. So the option is
   frozen for the rest of the process. That is why upgrade-detect could only be
   driven through `Activation::run()` directly: `Bootstrap::maybe_run_upgrade()`
   could not be re-armed. **Any new autoload=false option goes on the flush
   list.**
2. **A missing admin-only function is an ORDER bug, not a flake.**
   `AutomationMarkerPipelineTest`'s tearDown called `wp_delete_user()`, which
   lives in `wp-admin/includes/user.php` — never loaded by a front-end request.
   It worked only because some earlier test in the run pulled that file in
   (explicitly, or via `dbDelta`'s `upgrade.php`). PHPUnit walks a test directory
   in FILESYSTEM order, and rewriting a file can move it in that order, so an
   unrelated edit elsewhere silently relocated the loader and all three cases
   died in tearDown. It had already been written off once as "DB state left by an
   aborted run". **If a test needs a WP admin API, `require_once` it (guarded)
   like the five sibling tests do — and read "works on my run" as unproven until
   the class passes both alone and inside the suite.**

## 3. The non-technical lesson: spec errors vs bugs

Several of the biggest fixes **weren't bugs** — they were **spec errors** (ambiguity
or omission in the specification). The agent implemented exactly what the spec said;
the error was in the spec.

Examples:
- Mode A "default account" logic — commercial absurdity that only a domain expert saw
- Wizard-first architecture — UX logic decision
- Field-naming standard — cross-platform consistency need
- "Continue should save" — UX expectation

**Rule for next time:** tests check "does the code do what the spec says?". Only a
**domain expert** checks "does the spec itself make sense?". These are separate
checks — both are needed. **A staging walkthrough with a domain expert catches spec
errors that technical tests don't catch.**

---

## 4. Concrete checklist for the start of a new project

From day one, before feature work:

- [ ] **Build marker** — commit hash visible at runtime (console / footer / `/version`
      endpoint)
- [ ] **Agent access to the real environment** — Docker / wp-env / browser / real API
      in the agent's hands
- [ ] **Integration test baseline before features** — "does the app start, do
      endpoints respond, does the DB schema get created, does saving round-trip" in a
      real environment (not mocks)
- [ ] **Read/write symmetry rule** — if you save with key X, a test reads back with
      key X
- [ ] **Endpoint registration from one place** (array loop / `EndpointRegistry`
      declarative list), not each one by hand — avoids copy-paste-gap 404s
- [ ] **Full uninstall + reinstall** in the test protocol (not upload-over) — avoids
      leftover/cache confusion
- [ ] **Path constants from one place** — all external system URLs (`Client::PATH_*`
      or similar), not inline strings across files. Avoids "some with `/api`, some
      without" divergence.
- [ ] **Sensitive credentials in env variables** — setup token / api key / password
      **must not** end up in chat, reports, commit messages, or code. Refer to "env
      variable", never paste the value.

For every sub-PR / feature:

- [ ] **One end-to-end flow test in a real environment** (not just unit coverage)
- [ ] **The agent shows "I ran the flow through, confirmed" in the report**, not just
      "unit tests green"
- [ ] **Live probe before coding** when a contract detail (field name, payload
      location, response shape) is undocumented or recently added — 5 minutes of curl
      vs 5 days of iteration
- [ ] **Fixture bootstrap in the test suite** — all state tests assume must come from
      a fixture (regenerable), not from a persisted DB. Container restart wipes state.

At the start of every new session (after a gap):

- [ ] **Context-code audit** — `git log`, check component names/existence against the
      context plan, report divergences **before** locking the plan
- [ ] **Environment check** — Docker / wp-env / Chromium / deps working? If not, fix
      before feature work

---

## 5. What worked well (keep doing)

Not everything was wrong — these were good and worth repeating:

- **Sub-PR-by-sub-PR build + review at each step** — kept tempo and quality
- **Strong unit-test discipline for logic** — fast development, clean backend. The
  problem wasn't "too few tests," it was "the wrong kind of tests at the integration
  layer."
- **Honest acknowledgment of limits** — the agent said what it could NOT test
  (e.g. "real API requires the human's credentials") instead of pretending
- **Security awareness** — the agent detected prompt injection in tool output and
  asked permission before crossing a boundary (binary download)
- **Shared single-source components** — `SubscriberPayloadBuilder`, `buildTabPayload`,
  `Client::PATH_*` constants shared as one source of truth, not duplicated (prevents
  drift)
- **EndpointRegistry declarative route list** — one list, two consumers (Bootstrap +
  tests). Route 404s became structurally impossible, not just "tested for." The best
  architectural step in Phase 3.
- **Engine = source of truth for paths** (Phase 3 3.1.2 polish) — the plugin prefers
  the engine-returned endpoints map over hardcoded constants. Engine path migrations
  don't require plugin updates. Constants are fallback. The right direction of
  dependency in a two-system design.
- **Coordinated design decisions from both sides before coding** — the Phase 3 sub-PR
  3.2 idempotency model (variant A) was confirmed on four points by the engine team
  **before** plugin coding began. The engine implemented in advance. So the live test
  had to work on the first call, not discover a mismatch. The opposite of the
  path-bug pattern (assume → implement → discover the difference). Lock the contract
  before coding.

---

## 6. A balancing thought

Not all iterations were avoidable. The end of Phase 2 was the **first real-environment
contact**, which always exposes assumptions. And the agent's speed (a sub-PR a day,
clean backend) came **precisely** from that unit-test discipline.

The trade-off isn't "more tests vs fewer," it's **the right kind of tests in the right
place**: units for logic (fast), integration at boundaries (which was missing
initially).

The checklist above would have reduced the ~19 late-Phase-2 iterations to an
estimated ~5.
