# Changelog

All notable changes to Mining Manager will be documented in this file.

## [Unreleased]

### Moon Planner

- **Blueprints**, a new tab beside the planner. A blueprint is a repeating pattern of pulls: which refinery, which weekday, what EVE time, over one to eight weeks. Apply one to the planner from any date, for as many cycles as you ask for, and it writes ordinary planned pulls. The planner could only take one pull at a time before, or a guess from history, and neither matches running a set of moons on set days.
- **Applying shows you everything first.** Every occurrence is listed with its date and refinery, and marked as it will be written, skipped (before the start date, already past, or that refinery is already planned within half an hour), or landing inside the minimum gap of another moon. Nothing is saved until you confirm. The list works itself out as soon as the dialog opens and again whenever you change the blueprint, the date or the cycle count, so there is nothing to press first, and when there is nothing to write it says why instead of leaving the button dead.
- **Applying a blueprint can take over the weeks it covers.** Normally a day that is already planned is skipped and kept. Tick "Make this blueprint the plan for these weeks" and everything else planned in those weeks goes first, including days the pattern does not use, so replacing a five-day rotation with a three-day one does not leave the other two sitting there. The dialog lists what would be removed and where each pull came from, and asks again before writing. Pulls already running, pulls matched to a real extraction, and anything outside those weeks are left alone.
- **Editing a blueprint offers to carry the change to the calendar.** Pulls it already wrote follow the new pattern: slots that moved move, slots taken out are removed, slots added appear in every cycle still ahead. Anything already matched to a real extraction, and anything in the past, is left exactly as it was.
- **A refinery belongs to a blueprint once.** The picker only offers refineries the pattern does not already use, and the count says how many are placed and how many are left, so a moon cannot be scheduled twice by accident or quietly left out.
- **Every refinery is labelled with its moon's tier**, R4 to R64, in the picker and on the pattern itself.
- **Click a pull in the pattern to change its refinery or time**, or remove it from there. Removing is a button rather than a faint cross.
- **A refinery that has been unanchored, destroyed or handed over is flagged** in any blueprint holding it, and applying never plans a pull on one. One button clears them out of every blueprint along with the pulls they had planned ahead. It is not automatic: a structure missing from SeAT for a moment during an ESI wobble would otherwise delete a pattern somebody spent time building.
- **Plan from Blueprint happens on the planner**, in a dialog, rather than sending you to another tab. With no blueprints saved it says so and offers to open the tab that makes one.
- **Moving or removing a pull from a blueprint asks whether to carry it.** Taking the later ones with it moves them by the same amount, so the pattern keeps its spacing rather than collapsing onto one date.
- The **Plan Pull** refinery dropdown is sorted by system, then by name. It was in the same order as the cards down the side, which is useful there and no use at all when you are looking for one rig in a list.

## [2.0.4] — 2026-09-23 — The Ecosystem Era: Payments and Balances

Wallet payments, rebuilt. A member who sends tax ISK without pasting the tax code used to leave a transfer that nothing could match and no button could resolve. It can now be assigned to the invoice it was meant for, whatever a payment does not settle rolls onto the next unpaid invoice, and anything left over is held as account balance that members can see and directors can give back. Around that: the personal mining import counts the whole day, the ore registry catches up with everything CCP has shipped, the Extraction Simulator gains a moon search, and price refreshes stop asking for one ore at a time.

> Mental model: a wallet transfer is money looking for an invoice. Matching it by tax code is the fast path; assigning it by hand is the fallback. Either way the transfer is claimed exactly once, and every invoice it touches records its share. An invoice that has gone out is a record, not a calculation.

**Backwards compatible.** Nine new migrations, none of which alters or drops a column, and no new ESI scopes. Invoices already issued keep their totals, and mining already in the ledger keeps the categories and rates it was billed on. Upfront payments stay off until you switch them on, and the outstanding digest sends nothing until it is bound to a webhook. Cascading a remainder and holding surplus as balance are on by default, for payments from the update on, and both are switches under Settings, General.

### Wallet Verification

- **Assign to invoice** on every pending payment: shows who paid and how much, lists that player's open invoices (alts included when Treat a player's characters as one account is on), and credits the payment where you point it.
- **Remainder cascades** onto the next oldest unpaid invoice and keeps going until the money runs out. **Surplus is held as account balance** and comes off their next invoice. Both are switches under Settings, General, Payment Settings.
- **Hold as account balance** is offered for a codeless payment from somebody who owes nothing, and picked automatically when they have nothing outstanding.
- **Undo** reopens the invoices and returns the transfer to the queue. Refused once part of the surplus has been spent elsewhere.
- Status badges say why a payment is waiting: no tax code, unknown code, not yet applied, or before the cutover. The **Mismatched** tile now means a code matching no invoice, instead of repeating the pending count.
- Payments from before the cutover that carry a valid code are hidden, since the old pipeline recorded only the most recent payment per invoice and cannot prove the earlier ones were credited. Codeless ones stay visible, because nothing could ever have matched those automatically. **Show them anyway** reveals the rest.
- Fixed: **Sync, Auto-Match, Verify and Dismiss all did nothing.** `toastr` was never loaded on any page in the plugin, so the first line of each handler threw. Verify also re-ran the same code matcher that had already rejected those rows, and read `character_wallet_journals` where the page reads the corporation journal. Sync called a method that does not exist and answered 500. A batch where every row failed still returned 200 with a green toast.
- Fixed: a partial payment could be credited twice, because the guard read a single `transaction_id` column that every new payment overwrote.
- Fixed: a tax code typed into the transfer's **reason** was invisible to two of the three matchers, which read only `description`.
- Removed a wallet journal listener bound to a SeAT event that does not exist, and which read the wrong side of a donation.
- Mark as Paid, bulk Mark as Paid and the status dropdown now record a payment row, so hand-settled invoices reconcile like any other. Bulk Mark as Paid also marks tax codes used.

### Balances (new tab)

- Directors see everyone holding a balance, the corporation total and how much has been applied; members see their own, alt-aware. Every balance lists what it was spent on. The tab hides itself until somebody holds a balance or upfront payments are on.
- **Upfront payments** (Settings, Features, off by default): a standing keyword in the transfer reason, `MM-UPFRONT` by default, lets a member pay before being invoiced. It settles what they owe oldest first and holds the rest. A tax code still wins if both appear. The keyword and its switch are global, and cannot overlap the tax code prefix.
- **Refund** returns a held balance, full or partial, with a required reason. Nothing sends ISK: EVE has no API for it, so a director makes the transfer in game with `MM-REFUND` in the reason and the plugin watches the corporation wallet for it, across every division. Until it arrives the refund reads **Awaiting transfer**.
- **Mark as sent** closes a refund that will never match, with a note, and reads **Sent (by hand)** rather than **Sent**. **Undo** is offered for those, but not for a refund matched to a real transfer.
- A refund can go to any character on that player's account, reading the same accept-alts setting tax and upfront payments use. Two transfers that both fit leave the refund pending rather than guessing.
- Switching upfront payments off leaves held balances alone: they stay spendable, because the money is the member's. Holding surplus as credit is a separate switch, and a director can still bank a payment by hand, with a warning that doing so overrides a setting.

### My Taxes

- **Account Balance** panel, shown only when there is a balance, alt-aware so a surplus sitting on a mining alt is visible against the account it belongs to.
- An invoice settled from balance says so, on the page and in its notes, so it reaches the exports and the receipt.
- Fixed: the payment steps told members to pay from their own wallet, which cannot send ISK to a corporation. They now say to right-click the corporation in game and choose **Give Money**.
- Fixed: Tax History put the first half of a fortnightly month above the second, because both halves carry the same month value. Ordering is now on the period's own start date, here, on the overview and in every export that lists tax records.

### Tax pages

- **Tax Overview** opens on what needs chasing: overdue, unpaid, partly paid, settled, newest period first. Every money and date column sorts as a number rather than as text, which is why the page used to look shuffled. New **Outstanding (not fully paid)** filter, which is where the digest links to.
- **Tax Codes**: admins can **Mark used** on a code whose invoice is settled, and **Delete** one that should not exist at all. Periods sort properly here too.
- **Calculate Taxes** opens grouped by account, with the flat list one click away. **Regenerate Codes** is gone: it ran what Recalculate runs, and an issued invoice never gets a new code. Every button explains itself on hover.
- **Invoice detail** gains a **Payments received** table listing every payment credited to it, with amount, date and origin. `transaction_id` only ever held the most recent one, so instalments were invisible.
- Fixed: **a partly paid invoice was never chased.** Reminders and the invoice generator selected only unpaid and overdue, so it was never invoiced for the remainder, never reminded about, and could never become overdue however late it got. Where an amount was quoted it ignored what had been paid. Fixed in all four places that ask somebody for money.
- Payment codes are now minted when the tax record is created, so a part-paid invoice still carries the code a member needs to pay the rest.
- Reminders and overdue notices name how much of the period was already met from balance, on Discord, Slack and EVE mail.
- New **Outstanding Mining Tax** digest: a weekly list for directors of who still owes, largest first, following the invoice chain rather than a fixed weekday. Per-webhook opt-in, corp-scoped so it never reaches the members it names.
- Fixed: `calculate-taxes --recalculate` could rewrite an invoice that had already gone out. Both recalculation paths now leave an invoice alone once it has a payment code, money against it, or a paid or partial status, and log the recalculated figure instead of storing it.

### Mining ledger and ore classification

- **Personal mining is counted in full.** SeAT adds a row each time a fetch finds the day has grown, and the import was taking each row as the whole day. On an install that taxes belt, ice or gas, bills from this update on will be higher. The import still reads the last two days by mining date, so invoiced days do not move, and nothing already in the ledger is recounted.
- `mining-manager:import-character-mining --dry-run` walks the same path and prints the same table without writing anything, including which dates new entries would carry.
- **Event ore, quest ore and Mutanite are left out entirely**: Tyranite, Nephrite, Dense Moissanite, Amethystic Crystallite, Hiemal Tricarboxyl Condensate, Volatile Ice, Veldspar Isotope, and all six Mutanite types from Homefront Operations. Not imported, taxed, valued or charted. Rows already in the ledger stay as they are. Reported in [#3](https://github.com/MattFalahe/Mining-Manager/issues/3).
- **The registry catches up with CCP**: 401 type ids to 540. IV-Grade for all fifteen classic ores, the Exordium 0-Grade tier, four X-Grade families, Prismaticite, nine gas colours plus 27 compressed gas types and Fullerite-C32, base and compressed Rakovene and Bezdnacine, and 63 batch-compressed variants recognised but deliberately unpriced. Neo-Jadarite is priced too, the material Nesosilicate Rakovene reprocesses into, so that ore's refined value is no longer short by the whole of it. Comments updated for CCP's rename of every ore variant to the numeric scheme.
- **New categories apply from this version forward.** A cutover is stamped, so mining already in the ledger keeps the rate and categories it was billed on whatever recalculates it later. `update-ledger-prices` and `backfill-ore-types` both stop at it, and `backfill-ore-types` gains `--scope`, `--dry-run` and a report of every category movement.
- **Mining that arrives after the bill is marked, not quietly charged.** A late row keeps its quantity and value, carries a zero rate and holds a note saying why, shown on the entry detail page. Where observer data is catching up on a row already billed, only the extra is added, and the billed value and tax stay exactly as they were. The ISK on genuinely late mining is not recovered.
- Fixed: **cross-source dedup could rewrite billed mining.** The import-time dedup and two orphan sweeps took no interest in whether an invoice covered a row. All three now leave covered rows alone and log each one they skip.
- Fixed: the nightly `update-ledger-prices` **never asked which ore you actually tax**, so the entry detail page could show a tax figure for mining that is not taxed and appears on no bill. Invoices were never affected, since they are built from the daily summaries.
- One `OreClassifier` replaces the same decision copied into six places, two of which had already drifted. No ore that can be mined changes category.
- The **reprocessing calculator** lists ore names it could not recognise above the results instead of dropping them silently, which usually means your static data is behind CCP's names.
- Fixed: the Diagnostics test data generator paired most of its type ids with the wrong ores, including two that do not exist in EVE.

### Extraction Simulator

- **Find Moons**, a search across every scanned moon, above the simulator. Filter by region, constellation and system (pickable in any order, and picking a system fills in the other two), security band, class, moon ore share, ores a moon must contain, composition rules such as R16 at least 20%, value over a chosen number of days, and quality. Click any column heading to sort by it, again to turn it around. **Simulate** opens a moon below, **Export CSV** downloads every match, and simulating a moon also lists up to three better scanned moons of its class nearby.
- Listing every valuable moon in a region at once is far stronger intel than looking them up one at a time, so Find Moons needs Director, Moon Manager or the new **Moon Finder** permission. Members keep the simulator itself.
- **Moon name** finds a single moon from any part of its name, such as `9OLQ-6 V - Moon 15`.
- **Quality is rated within a class.** It was a fixed ISK amount per 28 days, so an R4 moon could barely rate above Poor and a rating moved whenever prices did. A moon is now ranked against every scanned moon of its own class: Exceptional is the top 10%, Excellent the top 25%, Good the top half, Average the top 75%, Poor the rest. A class needs five scanned moons before anything in it is rated.
- **Refined value leads**, here and in Find Moons. Raw moon ore barely trades, so one thin sell order can put a silly price on a moon, while what the ore reprocesses into is steady. Both figures are always shown and both are named. Settings, Pricing decides which one leads, and Find Moons can switch per search with **Value by**.
- **Prices come from the cache** the scheduled refresh keeps, instead of a live request per ore every time somebody presses Simulate.
- **The simulator says when the figure on screen is not the one tax uses.** Which value leads and which one tax is worked out from are separate settings, so they can disagree, and when they do the simulation and Find Moons say so, with a button to switch. A missing price is named only where it changes the figure shown: a raw ore with no market counts as 0 in the ore value, which is normal, since raw ore mostly trades compressed or refined, and a refined material with no price leaves the refined value short.
- **Mark the moons somebody else holds.** ESI only reports our own structures, so a search over a region cannot tell which moons are taken. A result can be marked with the corporation or alliance holding it and a note, and carries a **Claimed** badge naming who reported it and when. **Moon is free** closes the report rather than deleting it, so a moon that changes hands keeps its history, and a claim clears itself once one of your own refineries drills that moon.
- **Watchlist.** Star a moon worth coming back to, with a note saying why, so the next person searching picks up where you left off. A watched moon drops off the list by itself once a refinery of yours drills it.
- Fixed: **moon ore share read as 100% on nearly every moon.** It summed every ore in the scan, regular asteroid ore included, so it said how complete the scan was rather than how much of the chunk is moon ore, and the **moon ore at least** filter had nothing left to exclude. Both the column and the filter now count the R4 to R64 ores only, so a moon that is half Veldspar reads 50%. The same figure under the simulator was wrong the same way.
- Moons one of your refineries still sits on are marked **Ours** automatically. Each of the three marks can be filtered on or hidden, and the CSV carries them.

### Moon Planner

- A scheduling mismatch now offers **Realign** (move the plan to the time the drill was really set for in game) and **Ignore** (keep the plan's time and record that the pull went ahead off-plan) instead of Dismiss. Both need a reason, both are saved to the change history with who did it, and neither moves later plans for that refinery.
- A cancelled extraction no longer raises a mismatch on the page, which matches the alert: that already left cancelled extractions out.
- Fixed: **placing a pull by hand has never worked, on any refinery.** The planner read a `moon_id` column off `corporation_structures` that SeAT does not have. That failed outright on save, and returned null quietly everywhere else, so auto-filled plans were stored with no moon and the calendar read **Unknown Moon**. The moon is now resolved from live extractions, then archived history, then SeAT's own extraction table. Saving also checks the structure is a refinery this corporation owns.
- Fixed: the schedule-mismatch alert could never finish, because writing the already-warned latch through the model tried to save display-only attributes as columns.

### Analytics

- Every page **opens on your own corporation** instead of All Corporations, which on a shared SeAT quietly showed a director other people's mining. All Corporations stays in the dropdown as a deliberate second choice.
- **Performance Charts** can be filtered by source (all mining, my moons only, all moon ore, other moons only), by ore category, and by player (picked by main, counted across every character that player mines on). Exports carry the same slice. The page says that "other moons" is inferred rather than recorded, and says when a filter is reading ore classification, since older rows carry the categories they were billed on. A filter that finds nothing says so instead of drawing five empty charts.
- Fixed: the **Moon Analytics month picker** never worked. Two inputs named `month` were submitted on every request, and the hidden one still held the month the page had been rendered with.
- **Moon managers can open Moon Analytics.** The rest of Analytics stays with directors. The Moon Planner link in the sidebar also shows for directors now, who already had access to the page.

### Notifications

- New **Price Provider Trouble**: says so once when price refreshes stop getting through and once when they work again. It goes by types that already have a price, so ore with no market is never mistaken for a provider that is down. Per-webhook opt-in, off by default.
- **Extraction Started** names the pilot who lit the drill and their main. With Manager Core that name is always there; without it the alert waits for the in-game notification to reach SeAT, and goes out without the name if six hours pass, so a missing notification can delay the alert but never lose it.
- Fixed: **"Fractured by" was always blank** on the moon pages. The plugin looked for that pilot in a format the in-game notification does not use. Chunks already recorded as fractured keep what they had.
- Fixed: **Extraction Started, Next Extraction Planned and Moon Scheduled Off-Plan posted to Discord with no detail at all**, because the Discord field builder never learned about them. "Next Extraction Planned" said the next pull was "planned below" with nothing below it. Slack and EVE mail were always fine.
- Fixed: **editing a webhook switched four alerts off.** The outstanding tax digest, Price Provider Trouble and the two cross-plugin extraction alerts were on the form but never filled in when a webhook was opened, so saving it for any other reason turned them off. Check your webhooks after upgrading, because anything switched off this way stays off. The icons in the webhook list left those out too, along with Extraction Started and the two planner alerts.
- Discord timestamps pick up an EVE time label, added only where the sender has not added one already.

### Pricing

- **Janice is asked once per hundred prices, not once per price.** A refresh made several hundred requests every four hours, each with up to six quick retries when one was refused. Janice publishes no rate limit, but its owner blocks keys for excessive traffic. Fuzzwork gained the same treatment; SeAT and Manager Core already read what they need in one go.
- A request that fails with a server error, a timeout or a rejection is retried in halves. A refusal (401, 403 or 429) stops the refresh instead of asking again in smaller pieces, which is exactly the traffic that gets a key blocked.
- **A failed lookup no longer wipes a good price.** A price that could not be fetched was written as zero with a fresh timestamp, which threw away the last good price and made the miss look like a current price of nothing. A price is written only when there is one, each side of the market on its own, and a type nothing came back for keeps what it had, stale timestamp included, so the next run still counts it as due.
- Fixed: **Janice with the split method priced everything at zero.** It read `effectivePrices`, which Janice's pricer never sends, so every split price came back as nothing. Split now uses Janice's own midpoint when both sides of the market have orders, and whichever side has them when only one does, rather than Janice's own figure, which halves the price when one side is empty. If you use split, prices are real from the first refresh after updating; invoices already issued keep what they were billed.
- Fixed: **scheduled refreshes skipped every other run.** A four-hour schedule against a four-hour cache duration met the previous run's prices a few seconds short of expiry and skipped them, so prices really refreshed every eight hours and read as stale for half of that. A run now refreshes anything older than half the cache duration. `--force` still refreshes everything.

### Settings

- **Allow Data Export** had never worked: every view reading it fell back to its own default and left the button on. It now means what it says, and **off is off for everyone**, directors and admins included, across mining, tax, analytics, theft, reports and the simulator's exports. The check is on the endpoints rather than the buttons, so an old export link answers with an explanation instead of a file. Your settings backup is deliberately outside this.
- **Settings export** now carries each row's corporation instead of collapsing a multi-corporation install into one context. Webhooks can travel too, but only when asked for, since a webhook URL is a credential. Files from the old export still import, treated as global, and say so.
- **One colour for each moon class**, everywhere. R4 to R64 were coloured three different ways, so the same moon could read gold on one page and grey on the next. Every page now uses the colours SeAT itself uses, set by the plugin rather than left to your theme.
- The sidebar icon is now the gem, matching the plugin map in Manager Core.
- Fixed: three buttons rendered their own translation key instead of a word.

### Diagnostics

- **Master Test** gains checks for the upfront payment setup, refunds still waiting on a transfer after a week, the personal import falling behind inside its two-day window, moon notifications not reaching SeAT, Extraction Started alerts stuck past their wait, the ore classification cutover, ignored ore reaching the ledger, and **Unrecognised ore types**, which lists any mined type the registry does not know.
- **Payment reconciliation** moves into the Master Test as well, so the one-click run says whether every paid or partly paid invoice raised since the cutover matches the payments recorded against it. An invoice raised before the cutover is left out even when it was paid after, because whatever the old pipeline credited it with has no breakdown to match. The Tax Trace tab keeps the full version, which also names transaction claims that produced no allocation.
- New Master Test check: **invoices carry their payment code**. Codes used to be minted only for invoices in certain states, so a part paid one could go out with no reference for the member to quote.
- New Master Test check: **Moon value shown matches the value taxed**. It warns when the simulator and Find Moons open on refined value while tax is worked out from ore value, or the other way round.
- The tax pipeline check gains **Step 4b: Payment Reconciliation**, checking the same invoices in full.
- **Data Integrity** flags account balances that cannot be right, refunds whose balance row is gone, and mined ore valued short for want of a price. Which price counts follows how you value ore: by refined value, the default, a refined material with no price; by ore price, a raw ore with none. A raw ore with no market price is expected and is not raised when you value by refined value, since raw ore is mostly traded compressed or refined.
- **Settings Health** lists the feature switches, and **Health Checks** counts payment allocations, held balances, pending refunds and planned pulls.
- Fixed: **Data Integrity counted resolved duplicates as duplicates.** Two rows for one character, day, ore and system are normal, because the character and observer endpoints both report the same mining and dedup keeps the personal row for the remainder. The rows dedup did resolve are soft deleted and were being counted as well. It now groups by source and ignores deleted rows.
- Fixed: **Tax Trace warned about every ore on an install that taxes one category.** "Taxable ore with 0% effective rate" and "has value but zero tax" fired on ore that is simply not taxed here, and on mining deliberately left untaxed because its period was already invoiced. One warning replaces them, and only when the category is switched on, carries a rate, and still came out at zero.
- Fixed: **the price cache looked unhealthy when it was not.** The Health Checks card, the price cache tab and `diagnose-prices` counted every type with no market as a failed lookup and every type keeping its last good price as stale, and `diagnose-prices` blamed the API key. Neither is a fault, and with prices now kept when a lookup comes back empty, the Health Checks card would have warned on every install. All three now go by the provider status the refreshes record: they warn only when the provider is failing or no price has been written for longer than the refresh allows, and show the rest as plain counts. Raw ore is no longer on the price cache tab's essential list.
- Fixed: `diagnose-prices --show-coverage` reported impossible percentages, such as Gas at 16 of 12, from hardcoded counts that had drifted from the registry.

### Commands

- `backfill-ore-types`, `backfill-event-records`, `backfill-extraction-history`, `backfill-extraction-notifications`, `restore-data` and `initialize` now hold a lock, released in a `finally`.
- `verify-payments --reset-month` shows what is at stake before it does anything: how many invoices, how much ISK, which allocation rows and payment codes. Then it asks. `--dry-run` stops there, `--force` skips the prompt, and a non-interactive run cancels rather than proceeding on a default.
- `update-ledger-prices --all-unpriced` reports how much it is about to touch and asks first.
- `generate-test-data --cleanup` now asks before it deletes. The prompt on that command covers generating rows and the cleanup ran before it, so the deletes happened before anyone had been asked anything. It now counts the test corporations and characters it is about to remove, says plainly that it reaches `character_infos` and `corporation_infos`, which belong to SeAT rather than to this plugin, and defaults to no, so a run with no terminal attached cancels instead of deleting.
- Fixed: `restore-data` could leave foreign key checks switched off if it threw on the way to re-enabling them.
- Fixed: Help offered a `--dry-run` for `detect-theft` that has never existed. It now points at the Theft Detection tab on the Diagnostics page for a look that records nothing.

### Permissions

- New `mining-manager.moon_finder`: Find Moons on its own, without the planner or analytics.
- `mining-manager.moon_manager` now also opens Moon Analytics and Find Moons.

### Schema

- New tables `mining_manager_payment_allocations` and `mining_manager_payment_credits` (`000022`).
- `000022` also backfills `mining_manager_processed_transactions` from the transaction ids already recorded on invoices and tax codes, so the new guard recognises what the old pipeline credited, and stamps the payment cutover.
- `000023` fills in the moon behind each existing extraction plan, so plans made before the planner could resolve one stop showing an unknown moon.
- `000024` adds the webhook column for the outstanding digest.
- `000025` stamps the ore classification cutover.
- `000026` adds `mining_manager_payment_refunds`.
- `000027` adds who confirmed a refund by hand and why.
- `000028` adds `mining_manager_moon_claims`, the moons somebody else already holds.
- `000029` adds `mining_manager_moon_watchlist`, the moons to come back to.
- `000030` adds the webhook column for the price provider alert.

No existing column is altered or dropped. Two of the migrations write to rows that already exist, and neither changes an amount, a status or anything a member was billed: `000022` records the transactions older invoices were already credited with, so they can never be credited a second time, and `000023` fills in a moon only on plans and history rows that have none.


## [2.0.3] — 2026-07-24 — The Ecosystem Era: The Moon Planner

The Moon Extraction Planner: a corp-internal calendar for staggering refinery pulls so chunks don't clump faster than a small crew can mine them. SeAT can only read the extractions a director fires in-game, so the planner is a coordination tool — it never controls the structure. Additive: one new permission, two new tables, a handful of columns, three opt-in notifications. No new ESI scopes.

### Moon Extraction Planner

- New **Moon Planner** page under Moon Manager, gated by the standalone `mining-manager.moon_manager` permission (directors and admins included).
- Shows three months at once, all in **EVE time (UTC)** — the clock the in-game structure scheduler uses. The add/edit form takes EVE time and confirms what that is in your local zone.
- **Auto-fill from history** projects each refinery's pulls from its arrival cadence (needs 2+ past arrivals) and spreads them to honour a configurable minimum gap (default 24h, set in Settings → Notifications). Refineries with too little history of their own use the corp-median cadence.
- **Re-anchor** a recurring day by moving a pull — future projections follow the new day.
- **Minimum-gap guard**: placing a pull within the gap window of another arrival asks for confirmation, enforced client- and server-side.
- Live and completed extractions are **locked** — they're set in-game, so the planner records them and can't edit them. Archived pulls stay visible.
- Per-refinery panel with cadence, next projected pull, a coverage badge (`Planned N×` / amber `Not planned` for a skipped moon) and a highest-ore-tier badge (R4–R64). Uncovered refineries sort to the top.
- **Change history**: who created, moved or removed each planned pull.

### Notifications

- **Extraction Started** — a refinery lit its drill. With Manager Core installed it's detected in about 2 minutes via ESI fast-poll instead of the ~30-minute moon-extraction endpoint cache. Toggle at Settings → Notifications → Extraction Started — Detection Speed.
- **Next Extraction Planned** — after a chunk is ready, announces the refinery's next planned pull.
- **Moon Scheduled Off-Plan** — a moon's in-game extraction is set more than 30 minutes off the plan; flagged on the calendar and pinged once.

All three are per-webhook opt-in and off by default.

### Schema

- New tables `moon_extraction_plans` (`000019`) and `moon_extraction_plan_audits` (`000020`).
- Additive columns on `moon_extractions` and `webhook_configurations` (`000019`, `000021`). All defaulted, no backfill.

## [2.0.2] — 2026-05-31 — The Ecosystem Era: Field Repairs

Bug fixes for issues that surfaced in production after v2.0.1: the moon-chunk-unstable warning silently never firing, the notification dispatcher skipping without saying why, the Mark-as-Paid modal freezing on click, and tax payments from alt characters being rejected. All additive, no schema changes, no new ESI scopes.

### 🐛 Critical bug fixes

**Moon-chunk-unstable notification silently never fired in production.** Root cause: `MoonExtractionService::determineStatus()` treated ESI's `natural_decay_time` field (the auto-fracture mark, ~3h after chunk arrival) as the chunk's expiry point. Every chunk got stamped `status='expired'` ~3 hours after arrival even though it had **50 more hours of mineable life** ahead of it. `CheckExtractionArrivalsCommand` Pass 2 filters `whereNotIn('status', ['cancelled', 'expired'])`, so the wrongly-stamped row was invisible to the cron and the capital-safety warning never fired.

Fix mirrors `MoonExtraction::scopeExpiredByTime()` math — the model has had the correct lifecycle logic all along, it was just `determineStatus()` that drifted apart. Prefers `fractured_at + 50h`, falls back to `natural_decay_time + 50h` as a conservative auto-fracture estimate when `fractured_at` isn't yet populated. Either way the +50h offset matches the actual chunk lifecycle (48h ready + 2h unstable). The UI's "Ready since" / "Unstable in 2h" pills were always correct because they used the runtime helpers (`getUnstableStartTime()`, `getExpiryTime()`); only the persisted `status` column was wrong, and only the cron read `status` directly.

Signature change: `determineStatus(array $data, ?MoonExtraction $existing = null)`. Update path passes `$existing` so the method can read `fractured_at` off the model. Create path (no existing row, so no fractured_at to read) calls without `$existing` and falls through to the `natural_decay_time` fallback — same as before for new rows.

**Recovery for installs already affected** (one-shot, run after deploy):

```sql
UPDATE moon_extractions SET status = 'ready'
WHERE status = 'expired' AND fractured_at IS NOT NULL
  AND DATE_ADD(fractured_at, INTERVAL 50 HOUR) > NOW();
```

After deploy, wrongly-stamped rows also self-heal on the next ESI import (~hourly) — the SQL just shortcuts the wait. Without it, you might miss the 2h unstable warning window for any chunk currently inside it.

**Notification dispatcher silently skipped with no reason.** `NotificationService::send()` had a silent `['skipped' => true]` early-return (no `reason` key) when the `isEnabled()` channel gate failed. The Diagnostic Notification Testing tab showed `reason: unknown`; operators couldn't tell whether the dispatcher had succeeded silently or failed silently. The combination of this + the lifecycle bug meant the moon_chunk_unstable warning could be silently broken at both the trigger layer AND the delivery layer with zero observable signal in any log line.

Fix: dispatcher now calls a new `describeChannelGateFailure()` helper that re-reads each channel signal (EVE Mail / Slack / webhook count) and constructs a specific human-readable reason — *"No enabled channel found: EVE Mail off, Slack off, 0 webhook(s) enabled. Tick at least one webhook in Settings → Webhooks, or enable EVE Mail / Slack in Settings → Notifications."* The reason is in both the API response (so the Diagnostic page shows it) and a WARNING line in `laravel.log`.

Companion fix: `hasAnyEnabledWebhook()` previously caught Eloquent exceptions silently and returned `false`, making every notification skip with no breadcrumb if any model/query issue ever surfaced. The catch block now logs the actual exception at WARNING level so the underlying cause appears in `laravel.log` instead of being swallowed.

**Mark-as-Paid modal froze on click.** Classic Bootstrap 4 modal interaction with AdminLTE: SeAT's `.content-wrapper` creates a local CSS stacking context (transform/filter/perspective on an ancestor). Bootstrap inserts `.modal-backdrop` at `<body>` level (z-index 1040), but the modal-dialog stays inside the wrapper and its z-index gets pinned to the local context. The backdrop ends up effectively above the modal-dialog in the stacking order; clicks land on the backdrop and the form looks frozen. The dialog still renders visually because the backdrop is translucent — so the bug was easy to misdiagnose as a JS handler issue.

Fix: reparent the modal element to `<body>` before show, escaping the wrapper's stacking context. Four direct `.modal('show')` call sites patched with `.appendTo('body').modal('show')`; one declarative `data-toggle="modal"` trigger got a delegated `show.bs.modal` event handler that does the same reparenting (no JS call site to patch).

Five surfaces total: `#markPaidModal` × 2 (taxes index + details), `#eventModal` (events calendar), `#extractionModal` (moon calendar), `#entryDetailsModal` (ledger index). Same latent bug would have hit all five but was less noticeable on the others until you tried to fill a form.

**Tax payments from alt characters silently rejected.** `WalletTransferService::processTransaction()` required the paying character (`transaction.first_party_id`) to be **exactly** the taxed character (`mining_tax.character_id`). Players routinely send tax ISK from their wallet-richest alt rather than the alt that did the mining — those payments dropped on the floor as 'unmatched' even though the tax code in the transaction description was correct.

Fix: the auto-match now accepts a payment if the tax code matches AND the paying character shares a SeAT `user_id` (via `refresh_tokens`) with the taxed character — i.e. is a recognised alt of the same player. New helpers `getCharacterIdsForUserOf()` + `sharesSeatUser()` encapsulate the lookup. Two call sites updated: `processTransaction()` (the listener-triggered auto-match path) and `manualMatch()` (the transaction-to-tax pairing entry point used by the tinker recovery recipe).

New setting `payment.accept_alt_characters` (default `true`) governs the behaviour. UI toggle exposed in **Settings → General** as a switch directly below "Auto-match wallet payments". Default value means the alt-aware behaviour is on automatically; operators who want strict pre-v2.0.2 matching opt in explicitly.

Audit log fires INFO when a payment is credited through an alt (paying char ≠ taxed char). `laravel.log` carries the tax_code, both character IDs, the transaction_id, and the amount. Same-character payments stay quiet. Directors disputing "who actually paid?" can grep the log for `"Payment credited via alt character"`.

**Parallel-implementation gap closed.** `VerifyWalletPaymentsCommand` (the artisan `mining-manager:verify-payments` command) had its own copy of the tax-code lookup loop rather than delegating to the service. The alt-aware fix in the service didn't reach the artisan command, so `--auto-match` kept using strict matching until a follow-up commit applied the same alt-aware logic inline. The artisan warn line now includes the searched-character list when alt mode is on — *"Tax code 'XYZ' not found for character N (searched M linked characters: ...)"* — so failure cases surface the eligible-id set in the operator output.

### 🚀 Permanent backstop

**New `mining-manager:validate-lifecycle-integrity` daily cron.** Permanent backstop against the class of bug that produced the lifecycle status incident: walks `moon_extractions` rows updated in the last 14 days (configurable via `--days`), computes the expected status via the same `fractured_at + 50h` math the runtime helpers use, and warns when persisted status diverges. With `--fix`, applies corrections in place.

Scheduled daily at 03:00 UTC via `ScheduleSeeder` with the `--quiet-ok` flag (no output when there's nothing to report — so the daily cron line stays a no-op when everything's healthy). Non-zero exit code on divergences so SeAT's schedule history reflects "needs attention" even when stdout is quiet.

Three flags:
- `--fix` — apply corrections in place. Each correction logged at INFO with from/to status.
- `--quiet-ok` — suppress success output. Used in the cron schedule line.
- `--days=N` — bound the audit window for performance on large installs (default 14).

Statuses skipped: `'cancelled'` (operator-applied; not derivable from time) and `'fractured'` (legacy/transient).

Diagnostic surfacing: the cron's category in Health Checks → Scheduled Jobs is `integrity` — distinct from `moon` / `metenox` / `tax` / etc. — so a failing audit stands out as a data-integrity signal rather than a routine extraction op.

Operator workflow when the cron flags a divergence:
1. Open Diagnostic → Health Checks → see the `integrity` cron's last exit code.
2. Run `php artisan mining-manager:validate-lifecycle-integrity` manually to see the divergence list.
3. Investigate the rows, or re-run with `--fix` to auto-correct.

### ⚙️ Modified surfaces

- `src/Services/Notification/NotificationService.php` — new `describeChannelGateFailure()`; `send()` skip carries a real reason; `hasAnyEnabledWebhook()` logs swallowed exceptions
- `src/Services/Moon/MoonExtractionService.php` — `determineStatus()` rewritten + signature change + caller updated
- `src/Services/Tax/WalletTransferService.php` — `processTransaction()` + `manualMatch()` now alt-aware; new `getCharacterIdsForUserOf()` + `sharesSeatUser()` helpers; INFO audit log on alt-credited payments
- `src/Services/Configuration/SettingsManagerService.php` — `getPaymentSettings()` + `getGeneralSettings()` expose `accept_alt_characters`; `updateGeneralSettings()` whitelist extended
- `src/Console/Commands/VerifyWalletPaymentsCommand.php` — alt-aware match parity with the service; enriched warn output naming the searched-character set
- `src/Console/Commands/ValidateLifecycleIntegrityCommand.php` — new (daily integrity cron)
- `src/Http/Controllers/SettingsController.php` — validation + checkbox conversion for `payment_accept_alt_characters`
- `src/Http/Controllers/DiagnosticController.php` — `integrity` category in `systemStatusScheduledJobs()` categoriser
- `src/Database/Seeders/ScheduleSeeder.php` — daily 03:00 UTC entry for the validator with `--quiet-ok`
- `src/MiningManagerServiceProvider.php` — `ValidateLifecycleIntegrityCommand` registered
- `src/Resources/views/settings/tabs/general.blade.php` — new alt-payments toggle below auto-match
- `src/Resources/views/taxes/index.blade.php`, `taxes/details.blade.php`, `events/calendar.blade.php`, `moon/calendar.blade.php`, `ledger/index.blade.php` — modal `appendTo('body')` patches (4 call sites + 1 event handler)

### ⚠️ Compatibility

- **Fully additive.** No existing schema changes. No public API change. No breaking setting changes. No new ESI scopes.
- New setting `payment.accept_alt_characters` defaults to `true` — the alt-aware behaviour is on automatically; operators who want strict mode opt in via Settings → General.
- `determineStatus()` signature is backward-compatible — `$existing` is optional with default `null`, so any external code calling the old signature keeps working.
- The validator cron's schedule row is seeded via `firstOrCreate` (canonical `AbstractScheduleSeeder` pattern) — existing operator cron customisations are preserved.
- The modal `appendTo('body')` patch is purely additive — modals that were working continue to work; the broken ones now also work.

### 🔧 Operator recipes

**Re-run historical payment matching** to recover alt payments that were dropped on the floor before deploy:
```bash
docker exec -it seat-docker-front-1 php artisan mining-manager:verify-payments --days=30 --auto-match
```
`--days=30` covers a month of wallet history. Catches alt payments retroactively now that the matcher is broader.

**Manually pair a specific transaction to a specific tax row** (handy for the double-payment-from-two-alts edge case where you want to control which transaction credits):
```php
// in tinker
app(\MiningManager\Services\Tax\WalletTransferService::class)->manualMatch($transactionId, $taxId);
```
Uses the alt-aware sharesSeatUser check; logs an audit line; returns true on success. There's no UI for this in v2.0.2 — if alt-payment double-sends become recurring, a UI control would slot into v2.0.3.

---

## [2.0.1] — 2026-05-26 — The Ecosystem Era: Polish Pass

Polish on top of v2.0.0's ecosystem features: faster director workflows (Discord role picker, notification routing map, Metenox cargo readout), cross-plugin event publishing, and per-surface quality lifts (live local-time conversion, hardened jackpot rendering, aligned diagnostics). All additive, no new ESI scopes.

### 🎉 Headline features

**Inline Discord Role Picker** — Each of the 17 notification types with `has_role_ping: true` (tax / event / moon / theft / report families) now has a "Pick" button next to its Discord Role ID input. Click it → an inline list of every Discord role known to your SeAT install slides down. Pick one → the snowflake fills the input → done. No more "enable Developer Mode in Discord, right-click role, Copy ID, paste here, repeat × 17". Detects all installed providers via table presence (`discord_roles` for SeAT Broadcast, `seat_connector_sets` for warlof/seat-connector, legacy `warlof_discord_connector_roles`). Any combination is supported; role lists merged + deduped by Discord snowflake. One AJAX fetch per page load, shared cache across all pickers. Picker buttons render conditionally on detected providers — installs with no Discord plugin keep the plain text input as the fallback.

**Moon Extraction EventBus Publishing** — Three new events published via Manager Core's Topics facade, fired exactly once per extraction per lifecycle stage:
- **`mining.extraction_ready`** — chunk has fractured, 48h fleet-able mining window opens
- **`mining.extraction_unstable`** — final 2h capital-safety window before expiry (48-50h after fracture)
- **`mining.extraction_expired`** — window closed, no more mining

Rich payload per event: extraction_id, moon_id/name, structure_id/name, corporation_id (for visibility scoping), full lifecycle timestamps, auto_fractured / is_jackpot flags, estimated_value, effective status, `schema_version=1`, plus a `url` field deeplinking to MM's per-extraction detail page so subscribers can pivot operators straight there. New cron `mining-manager:scan-extraction-events` runs every 5 minutes and uses per-stage latches in the new `moon_extraction_event_log` table to publish only stages not yet latched. Catch-up logic backfills earlier stages if a drill is first observed in `unstable`/`expired`. Standalone-safe via `class_exists` guard on `\ManagerCore\Topics`.

**Notification Routing Map** (Settings → Routing Map) — Read-only delivery snapshot showing every notification type, the webhooks it fires through, the corp scope, and the Discord role that will actually be mentioned at send time. Resolved with the same precedence the dispatcher uses (L1 per-type role / L2 webhook legacy role; tax_invoice hard-blocked from role pings). Summary chips: total / globally enabled / delivering / "enabled but firing nowhere" (warning). Flags `extraction_at_risk` / `extraction_lost` as dormant when Manager Core or Structure Manager is missing. Resolved role pills show the role NAME + colour from the Discord provider, not the raw snowflake ID. Mirrors Structure Manager v2.0.0's pattern.

**Metenox Drill Cargo readout** (Director-only, with admin scope picker) — New sidebar page `Mining Manager → Metenox Cargo` (also as a tab on the Moon Extractions section). One card per Metenox Moon Drill, showing what's currently in the drill's `MoonMaterialBay` — every ore stack with quantity, **m³ volume**, ISK value at current market prices, and percent-of-cargo bars. Per-drill **bay fill indicator** with a color-graded progress bar (green/yellow/red) showing `X / 500,000 m³ · YZ% full`. Header chips: drill count · ISK in cargo · **Avg bay fill % (with critical-bay warning)** · oldest data sample. Drills sorted by ISK descending so the most valuable cargo shows first. ISK valuation uses MM's existing `PriceProviderService` (Manager Core's pricing when configured; Jita / Fuzzwork fallback), batched into one round-trip per page render. Cross-plugin contract: PluginBridge capability `mining.metenox.cargoSnapshot($structureId)` returns `[type_id => quantity]` for any Metenox. Data source is SeAT's existing `corporation_assets` table (~1h ESI cache). No new ESI scopes. **System labels** show solar-system names (joined from `solar_systems.name`) with the numeric id as a small muted suffix; falls back to id-only when the SDE row is missing.

**Scope model:** Directors see only the **Moon Owner Corporation**'s drills (matches the Past Extractions table convention — keeps the page and the `metenox_cargo_full` notification aligned). Operators with `mining-manager.admin` land on the same Moon Owner Corp default but get a **corp scope bar** above the chips: a dropdown of every corp with at least one Metenox plus an "All corps" aggregate option, a one-click "Back to Moon Owner" shortcut whenever they're off the default scope, and a hint that the picker only affects this page (notifications still scope to Moon Owner Corp). If the Moon Owner Corp isn't configured, directors see a warning with a one-click link to Settings; admins fall back automatically to the All corps view so they can still browse drills.

**Metenox Cargo Bay Full notification** — New `metenox_cargo_full` notification type fires when a drill owned by the Moon Owner Corporation crosses the configured fill-% threshold going up (default 85%, operator-configurable 50-99%). Yield-stopping warning specifically — drilling stops when the bay caps out but the structure itself stays safe (different from `extraction_at_risk` which is structure-safety). New cron `mining-manager:scan-metenox-cargo-fill` runs every 5 minutes, scoped to Moon Owner Corp only so the scanner and the page show the same set of drills. Dedup latch in the new `metenox_cargo_alert_state` table prevents repeat fires while still over threshold; resets implicitly when cargo is pulled (fill drops back below threshold). Includes ISK valuation of cargo in the bay + deeplink to the Metenox Cargo page. Standalone — no Manager Core or Structure Manager required (works on bare-MM installs).

Bay capacity is **500,000 m³** for every Metenox, sourced from `dgmTypeAttributes` (attribute 5693, Metenox-only) and cross-verified against EVE Ref's published value at https://everef.net/types/81826 ("Moon Material Output Bay Capacity: 500,000 m³"). At typical Metenox production rates (~1,500-2,000 m³/hour) the bay fills in ~10-14 days, so the default 85% threshold gives operators ~2 days of lead time before the bay caps and drilling stops.

**Local time auto-conversion + live countdowns** — New `eve-time.js` (copy from SeAT Broadcast v2.0.0 canonical) wraps every server-rendered EVE timestamp. Hover any timestamp → tooltip with full local time formatted via `Intl.DateTimeFormat` against the browser-detected IANA timezone (same mechanism Discord / Google Calendar / GitHub use; DST handled automatically). High-priority surfaces (active extractions, upcoming events, calendar, my-events) opt into an inline " · HH:MM local" pill via `data-show-local` for at-a-glance reading. New `eve-countdown.js` (MM original, ~80 LOC) replaces `Carbon::diffForHumans()` text with a 1-second tick loop. Color-graded: green (>1d), yellow (1d-1h), red+bold (<1h), muted grey (past target). Event create / edit forms gained an `Enter time in: [EVE/UTC | My local]` toggle. Live confirmation box shows both interpretations as the operator types. On submit JS rewrites the value to UTC-as-datetime-local so the server receives canonical UTC regardless of mode. DST-safe via the browser's IANA timezone.

**Diagnostic page aligned to the suite-wide standard** — Default landing tab is now **Health Checks** (renamed from "System Status"). Nav reordered: Tier 1 universal tabs first (Health Checks → Master Test → System Validation → Settings Health → Data Integrity → Tax Trace), then Notification Testing, then plugin-specific traces, then DEV-only "Test Data" (with red `DEV` badge). Every Tier 1 tab opens with a "What this tab does / When to use / Heads up" intro box. Default landing eager-loads Health Checks data on page open.

**Diagnostic page covers the new Metenox cargo subsystem** — Health Checks lists the scanner cron under a new `metenox` category and reports drill / MoonMaterialBay / alert-latch row counts in Data Counts. System Validation gets a dedicated "Metenox Cargo Subsystem" card with seven server-side health probes (type 81826 in invTypes, migration 000017 schema bits, solar_systems populated, threshold setting in 50-99 range, scanner cron registered, Moon Owner Corp set) plus an overall Healthy/Warnings/Critical pill. Settings Health now iterates the Notifications group so the new `notifications.metenox_cargo_full_threshold_pct` setting surfaces. Data Integrity gets three Metenox-specific checks: stale latch rows (alerted > 60 days ago), orphan latches (referencing structures no longer in corporation_structures), and orphan MoonMaterialBay asset rows (parent missing or wrong type). Notification Testing gains a `metenox_cargo_full` entry in the dropdown with realistic default test data (92.4% fill, 450k m³, 850M ISK) so operators can smoke-test the new alert end-to-end without waiting for a real drill to fill.

### 🎨 Quality of life

**Jackpot rendering hardened against custom SeAT themes** — 18 inline-styled jackpot elements across 6 blades (Report Jackpot button, JACKPOT banners, every "2x multiplier" indicator badge) converted to override-resistant `.mm-jackpot` / `.mm-jackpot-badge` / `.mm-jackpot-alert` CSS classes with `!important` on background/color/border + nested icon colour. Custom SeAT theme installs (`custom-layout.css`) that use `!important` on `.btn-warning` no longer wash the black text out, leaving "yellow text on yellow button" invisible-on-hover renderings.

**Help & Documentation refreshed** — New "Time display & timezones" section under Events with live browser-TZ readout. New "Metenox cargo readout" section under Moon Mining covering data source, refresh cadence, permission model, ISK valuation, cross-plugin contract. "What's New in v2.0.1" section on the Overview page so operators upgrading from v2.0.0 land on the feature summary first. Diagnostic page intro paragraphs on every Tier 1 tab.

### 📦 New files

- `src/Services/Events/MoonExtractionEventPublisher.php` — EventBus publisher
- `src/Console/Commands/ScanMoonExtractionEventsCommand.php` — scanner cron
- `src/Console/Commands/ScanMetenoxCargoFillCommand.php` — Metenox bay-fill scanner cron
- `src/database/migrations/2026_01_01_000016_create_moon_extraction_event_log_table.php` — per-extraction dedup latch
- `src/Database/migrations/2026_01_01_000017_add_metenox_cargo_full_notification.php` — `notify_metenox_cargo_full` column + `metenox_cargo_alert_state` dedup table
- `src/Services/DiscordRoleResolver.php` — role-source detector + lookup map
- `src/Services/Moon/MetenoxCargoService.php` — Metenox cargo reader + PluginBridge backing + fill % math
- `src/Resources/views/moon/metenox-cargo.blade.php` — director-only Metenox page
- `src/Resources/views/settings/partials/_routing_map.blade.php` — routing-map partial
- `src/Resources/views/settings/partials/_role_pill.blade.php` — resolved-role pill
- `src/Resources/assets/js/eve-time.js` — EVE → local tooltip / pill converter
- `src/Resources/assets/js/eve-countdown.js` — live-tick countdown widget

### ⚙️ Modified surfaces

- `MoonController` — new `metenoxCargo()` action gated on `mining-manager.director`
- `MiningManagerServiceProvider` — registers `ScanMoonExtractionEventsCommand` + new PluginBridge capability `mining.metenox.cargoSnapshot`
- `Database/Seeders/ScheduleSeeder` — wires the scanner cron (firstOrCreate)
- `Http/routes.php` — `/mining-manager/moon/metenox-cargo` placed before the `/{id}` catch-all
- `Config/Menu/package.sidebar.php` + `Resources/lang/en/menu.php` — Metenox Cargo sidebar entry
- `Resources/views/settings/{sidebar,index}.blade.php` — Routing Map tab
- `Resources/views/diagnostic/index.blade.php` — Health Checks renamed + default + 6 Tier-1 intro boxes
- ~10 events/* and moon/* blades — `.eve-time` + `.eve-countdown` wrap on every absolute time + live countdown surface
- `Resources/views/moon/{show,active,extractions,index}.blade.php` + `analytics/partials/moon-extraction.blade.php` + `settings/tabs/webhooks.blade.php` — 18 inline jackpot styles converted to `.mm-jackpot*` classes
- `Resources/views/help/index.blade.php` — three new sections (What's New, Time display, Metenox cargo)
- `Resources/assets/css/mining-manager-dashboard.css` — `.mm-jackpot*` + `.eve-countdown-*` + `.eve-time-local` + `.diag-tab-intro` primitives

### 🎯 Manager Core pricing centralization (added 2026-05-28)

Late addition to v2.0.1 closing the **"MC config lives in two places"** gap that v2.0.0 left behind. Single source of truth for which market + price type Mining Manager reads from is now Manager Core's `manager_core_pricing_preferences` table — operator changes in MC's UI propagate to MM's actual price reads within one cache flush cycle, not on the 4-hour scheduled refresh boundary.

**Pricing settings tab rewrite.** When `provider=manager-core` is selected, the "Manager Core Configuration" panel is now a **read-only status readout** pulling MM's current preference from MC via the new `pricing.getPreferenceForPlugin` bridge capability (Market / Price Type / Provider routing / admin-overridden flag), plus a prominent **"Configure pricing in Manager Core →"** deep-link button resolved via `pricing.preferencesUrl`. The Variant dropdown (min/max/avg/median/percentile) is gone entirely — only variant=min produces meaningful tax + payout values (lowest sell = real buy price for an instant market buy), now hardcoded in `CachePriceDataCommand`. The page-level Price Type dropdown is hidden via JS when `provider=manager-core` (MC's pref owns it).

**Boot-time preference seeding.** `MiningManagerServiceProvider::registerCrossPluginPricingSubscription` now also calls `pricing.registerPreference('mining-manager', $market, $priceType, ...)` when MC is the configured provider. Idempotent on MC side via the `admin_overridden` flag — operator edits in MC's UI are never trampled by this boot call. Same call fires on save-path via `SettingsController::updatePricing` so first save populates MC's table.

**Live cache invalidation via EventBus.** New `PricingPreferenceChangedHandler` (in `src/Services/Pricing/`) subscribes to MC's new `pricing.preference_changed` topic via `registerPricingPreferenceSubscription` boot method. Filters payload for `plugin_key='mining-manager'` (no-op for other plugins) and flushes `mining-manager:prices` + `mining-manager:moon-values` cache tags so the next read goes fresh through the bridge. Subscribed UNCONDITIONALLY when MC is installed — handler filters internally so a later operator switch to MC works without container restart.

**Centralized market resolver.** New `SettingsManagerService::resolveMcMarket()` is the single point of bridge integration for "where does MM look up its MC market?". Calls `pricing.getPreferenceForPlugin` once per request (static method-var cache) and falls back to `'jita'` literal on bridge-call failure. `getPricingSettings()['manager_core_market']` now transparently returns this value, so every downstream caller (`PriceProviderService`, `CachePriceDataCommand`, `DiagnosticController`, `MasterTestRunner`) sees MC's authoritative market without code changes. Closes a real consistency gap from earlier work where the cache populate honored MC's pref but the LIVE read path still used the stale local default.

**Jita fallback now actually works on the MC path.** Was effectively dead code before — `$currentMarket` always equalled `'jita'` from the stale local default, so the `if ($currentMarket === 'jita') return $prices;` early-return at `PriceProviderService.php:711` was always hit. With the new resolver returning MC's actual market, items that come back as 0 from a non-Jita market correctly retry through Jita as a per-item safety net.

**Dead local state cleanup.** Migration `2026_01_01_000018_drop_unused_mc_pricing_settings` deletes `pricing.manager_core_market` + `pricing.manager_core_variant` rows from `mining_manager_settings`. `SettingsController::updatePricing` stops writing them. Both were dropped from the UI in this same v2.0.1 cycle and the only remaining reader was switched to MC's bridge.

**Bridge version-check call removed.** `bridge.requireMinimumVersion('1.5.0')` had no signal — MC starts at 1.0.0, no older MC version exists, so the version comparison always passed. The `class_exists` guard at the top of `registerCrossPluginStructureAlerts` is the actual "is MC available?" gate. MC keeps the capability registered for any future major-rework scenario; MM just doesn't call it anymore.

**Per-plugin provider override consumer (added 2026-05-29).** Companion to MC's Option B work — Mining Manager now passes its plugin key (`'mining-manager'`) as the optional 4th arg to `pricing.getPrices` on every MC bridge call. When the operator sets a `provider_override` on Mining Manager's row in MC's Pricing Preferences page (e.g. routing MM through Janice for Jita while Structure Manager continues through Fuzzwork for the same Jita), MC consults the override and does a live upstream fetch through that provider instead of reading its local cache. Three call sites updated:
- `CachePriceDataCommand::syncFromManagerCore` (scheduled cache refresh, every 4h)
- `PriceProviderService::getPricesFromManagerCore` (live read path used by moon-value / tax / payout)
- `PriceProviderService::applyJitaFallback` (per-item Jita retry — uses the same override so the fallback is consistent with the primary read)

The MC Configuration status panel on Settings → Pricing also grows two new badges showing "per-plugin override" vs "market default (provider)" so the operator can see at a glance which routing is in effect. Reads the new `provider_override` + `market_provider` fields from MC's `pricing.getPreferenceForPlugin` response, with a defensive fall-through to the existing display if those fields are ever absent from MC's payload.

**Cache impact when override is set:** every MM cache refresh hits the override provider live (one batch upstream call per refresh cycle, every 4 hours via the scheduled cron). Acceptable bandwidth even with strict-rate-limit providers like Janice. When no override is set, MC reads its local cache (current behavior, unchanged).

**Operator workflow:** MC → Settings (set Janice API key) → MC → Pricing Preferences → find Mining Manager row → change "Provider Override" dropdown from "Use market's provider (Fuzzwork)" to "Janice" → Save → MC publishes `pricing.preference_changed` → MM's handler flushes the local cache → next cache refresh fetches through Janice. End-to-end self-serving — no MM-side restart required.

**Files added in this section:**
- `Services/Pricing/PricingPreferenceChangedHandler.php` (new, ~90 lines)
- `Database/migrations/2026_01_01_000018_drop_unused_mc_pricing_settings.php`

**Files modified in this section:**
- `Resources/views/settings/tabs/pricing.blade.php` — MC Configuration panel rewrite
- `Http/Controllers/SettingsController.php` — drops variant/market writes; new `pricing.registerPreference` call on save
- `MiningManagerServiceProvider.php` — new `registerPricingPreferenceSubscription` method; boot adds `pricing.registerPreference` call; `bridge.requireMinimumVersion` call removed
- `Services/Configuration/SettingsManagerService.php` — new `resolveMcMarket()` helper; `getPricingSettings()['manager_core_market']` now consults MC via bridge
- `Console/Commands/CachePriceDataCommand.php` — variant hardcoded to 'min'; market read via `getPricingSettings()`
- `Http/Controllers/DiagnosticController.php` — uses `getPricingSettings()` market resolver

**Requires Manager Core v1.0.0** (Manager Core's first stable release) with the new pricing capabilities (`pricing.getPreferenceForPlugin`, `pricing.preferencesUrl`, and the `pricing.preference_changed` topic published from `PricingPreferencesController`). Defensive try/catch on every bridge call — `resolveMcMarket()` falls back to `'jita'` literal if the capability call ever fails, so the Help page and pricing reads never error out.

### ⚠️ Compatibility

- **Fully additive.** No existing schema changes, no released migration touched, no public API change, no breaking setting changes.
- New `moon_extraction_event_log` table created on plugin boot via migration `000016`. Additive.
- New `metenox_cargo_alert_state` table + column on `mining_manager_settings` via migration `000017`. Additive.
- Migration `000018` deletes two now-unused settings rows (`pricing.manager_core_market`, `pricing.manager_core_variant`) — was operator-configurable in the UI in v2.0.0 but those dropdowns are gone in v2.0.1. Forward-only, idempotent.
- New schedule rows added once via `ScheduleSeeder` (`firstOrCreate` semantics — existing cron customisations preserved).
- Without Manager Core installed, the extraction scanner is a no-op and nothing changes from v2.0.0 behaviour. MM falls back to its own provider stack (SeAT / Fuzzwork / Janice).
- Metenox Cargo page is gated by `mining-manager.director`. No backfill required.
- Routing Map is read-only.
- Subscribers of the extraction events honour visibility scoping via the `corporation_id` field on each event payload.
- The MC pricing centralization is **transparent to existing installs** — first boot after upgrade calls `pricing.registerPreference` to seed MC's row from MM's existing settings; subsequent operator changes flow through MC's UI. Pre-existing operator MC-side preferences (if any) are preserved via the `admin_overridden` flag.
- **Zero impact on the rest of the plugin** — tax calculation, extraction tracking, theft detection, jackpot detection all unchanged.

---

## [2.0.0] — 2026-05-03 — The Ecosystem Era

Mining Manager becomes ecosystem-aware. It still works standalone, but when **Manager Core** is installed it uses centralised pricing via the PluginBridge contract, and when **Structure Manager** is installed it subscribes to structure-threat events and dispatches `extraction_at_risk` / `extraction_lost` alerts. Both integrations are optional; existing v1.0.3 installs upgrade cleanly.

### 🎉 Headline features

- **Cross-plugin alerts (MC + SM)** — `extraction_at_risk` (fuel critical, shield/armor/hull reinforced) and `extraction_lost` (refinery destroyed) notifications via Discord/Slack/Custom/EVE Mail. Includes attacker info, system security, fuel/timer details, severity-aware embed colors, and a one-click Structure Board deeplink to SM. Toggles auto-disable when either MC or SM is missing.
- **Master Test diagnostic** — one-click read-only smoke chain on the new default Diagnostic tab. ~26 checks across schema integrity, settings consistency, cross-plugin integration, pricing path, notifications, lifecycle, tax pipeline, and security hardening. Sub-30-second runtime. Pass/warn/fail/skip table with category badges + "Show only issues" filter.
- **Auto-match wallet payments toggle** — Settings → General → Payment Settings checkbox. ON (default) = listener applies matched payments automatically; OFF = matches detected and listed on Wallet Verification but require manual confirmation. Useful for installs wanting a human-review step.
- **Manager Core pricing integration** — when MC is the configured provider, MM consumes prices via `pricing.getPrices` capability. Boot-time idempotent re-subscribe with signature-cache fast path; staleness check on served prices (8h threshold) with structured warning logs.
- **Notification surface filled in** — `formatMessageForESI` now covers all 19 notification types (was ~60% pre-v2.0.0). EVE Mail recipients now get readable subjects + bodies for theft, jackpot, structure alerts, reports, etc., not raw JSON dumps.

### 🔄 Architectural changes

- **Three audit cycles** (cycle 1 hardening, cycle 2 cross-plugin contract drift, cycle 3 full audit Tier 1+2+3) shipped before this release. ~50 numbered findings across CRITICAL/HIGH/MEDIUM/LOW, all fixed.
- **Atomic compare-and-swap pattern** standardised across 4 race-prone sites: `StructureAlertHandler` dedup latches, `MoonController::reportJackpot`, `MoonExtractionService::sendMoonArrivalNotification`, `EventManagementService::updateEventStatuses` + manual paths. Same pattern across all 3 wallet-payment dispatch sites: `applyPayment`, `ProcessWalletJournalListener::handle`, `autoVerifyFromCorporationWallet`.
- **PluginBridge contract** fully respected — every cross-plugin operation (read, subscribe, unsubscribe, getPrice, getPrices, getTrend) routes through the documented capability surface. Direct `DB::table('manager_core_*')` queries eliminated.
- **Forward-only migration discipline** — 5 new migrations (000011-000015) for tax-code uniqueness, period_start backfill, orphan settings cleanup, discord_avatar_url add+drop. Migration 000001 untouched. Released-migration immutability rule honored.

### ⚠️ Compatibility notes

- **No breaking changes for standalone installs.** The plugin still works without Manager Core or Structure Manager — the cross-plugin features simply remain disabled and the relevant webhook toggles auto-grey-out in the UI.
- **`discord_avatar_url`** removed end-to-end. The field never worked correctly (was a duplicate of Discord's webhook UI avatar setting). Operators who tried to use it never got the override they expected; no behavior change in production. Forward-only migration 000015 drops the column.
- **`slack_webhook_url`** now requires `https://`. Slack webhooks have always been HTTPS-only at `hooks.slack.com`, so any existing valid value passes the new rule.
- **`ScheduleSeeder`** reverted to canonical SeAT v5 `firstOrCreate` semantics. The override that rewrote operator cron customizations on every plugin boot is gone. Existing installs keep whatever's currently in their `schedules` table (no behavior change on first upgrade).

### 🚀 What's next

- Pings plugin to subscribe to MM's events (Phase 3 of SM v2 roadmap, applies to MM too)
- Per-corp event webhook scoping (P6, deferred from v1.0.x cycles)
- EVE Mail channel for tax invoices to miners not in SeAT (planned for v2.1.0)

---

## Pre-2.0.0

A large audit and polish pass landed before v2.0.0 and rolled straight into it (no separate tag). The notification system was consolidated into a single dispatcher, the tax-lifecycle notifications (overdue, invoice) were fixed to actually fire, and robustness was hardened across the scheduled commands (locks everywhere, several race conditions closed). See the git history for the per-file detail.

## [1.0.3] - Event Accuracy, Period Awareness, Weekly Removal

Big release. Three parallel streams of work converged:

1. **Event tracking rebuilt** from the ground up. A new `event_mining_records` table materialises the exact mining activity qualifying for each event with all four scope filters (corp, location, time, ore category) applied at populate time. Tax attribution is now per-row — the modifier applies only to the actual event-window slice, not the whole day's mining. ISK saved during events is surfaced to miners on their pages and to directors on the dashboard.
2. **Bi-weekly tax period support matured.** The data layer already supported it; presentation and queries now do too. Period switches queue to a safe cutover date to prevent row collisions.
3. **Weekly tax period removed.** ISO weeks don't align to calendar months; the straddling weeks caused double-tax and chart aggregation problems. Biweekly covers the sub-monthly use case cleanly.

### Added

**Event System Refactor (Phases 1–3)**
- New `event_mining_records` table (migration `2026_01_01_000005`) — canonical record of which mining qualifies for each event. Populated by `EventMiningAggregator` with all filters baked in (corp + location + time + ore category). Moon events read `mining_ledger` (day-level observer data); belt/ice/gas events read SeAT's `character_minings` with datetime precision via `time` column.
- New `event_discount_total` column on `mining_ledger_daily_summaries` (migration `2026_01_01_000006`) — daily sum of ISK waived by event modifiers.
- New per-ore entries in `ore_types` JSON: `event_id`, `event_qualified_value`, `event_discount_amount`, blended `effective_rate`.
- New `mining-manager:backfill-event-records` artisan command — `--event=ID` / `--status=active|completed|planned` / `--fresh` for one-off rebuild after deploy.
- New `EventMiningAggregator` service — lazy-promoted via `MiningEvent::booted()` hook when any scope field (`type`, `corporation_id`, `solar_system_id`, `location_scope`, `start_time`, `end_time`) changes on save.
- `LedgerSummaryService::getEventAttributionForLedgerRow()` — per-row tax attribution. Modifier applies to the exact slice of mining that overlapped the event window, not to the whole day.
- Historical pricing preservation for non-moon events via proportional allocation from `mining_ledger.total_value` — backfilling an old event no longer rewrites ISK with today's prices.
- Event form **tax-compatibility panel** — badge row showing currently-taxed categories, reactive status block (🟢 full / 🟡 partial / 🔴 empty) based on the chosen event type, and a suggested event types list. Prevents running a "gas_huffing" event on an install that isn't taxing gas.
- Miner-facing event discount indicators:
  - **My Mining** — green callout "Event Discount Applied: you saved X ISK this period" + new orange small-box "Total Event Savings (All Time)" showing the running ISK total of tax waived from event participation across every event the user's characters have ever joined
  - **My Taxes** — top banner "Event discount applied this period: X ISK saved", plus per-ore "saved Y ISK" sub-line in the breakdown table
  - **My Events** — new full-width banner "Total tax saved from event participation: X ISK" near the top, plus a "Your tax saved: X ISK" line on every event card (active + completed sections)
  - **Ledger Summary** (director) — "incl. −X ISK event discount" under the Total Tax info-box
  - **Calculate Taxes** (admin) — Event Tax column now shows the real per-row discount (previously always 0 — column read a non-existent `event_tax_amount`)
  - **Director Dashboard charts** (Mining Tax, Event Tax) and **Member Dashboard** (Mining Income Last 12 Months) — gained a period-aware footnote under each chart when the install runs biweekly, clarifying that biweekly periods within each calendar month are summed into that month's bar.

**Savings-attribution helpers**
- `LedgerSummaryService::getTotalEventSavings($characterIds, $start = null, $end = null)` — fast sum of `mining_ledger_daily_summaries.event_discount_total` for a character set over an optional date range.
- `LedgerSummaryService::getEventSavingsByEvent($characterIds, $start = null, $end = null)` — walks `ore_types` JSON and returns `[event_id => ISK saved]` for per-event attribution (used by the My Events per-card line).

**Period Awareness (biweekly/weekly presentation)**
- `TaxController::myTaxes` resolves the configured period, queries by `period_start` (exact), falls back to oldest unpaid tax when the current period hasn't been invoiced yet, exposes all unpaid taxes to the view.
- My Taxes page uses period-aware labels everywhere — Current Balance card, Mining Breakdown header, Event Discount banner, "no tax this period" alert.
- New **"All Unpaid Periods" table** on My Taxes when more than one tax is outstanding — shows every period with amount, due date, status badge, details link.
- `TaxController::index` (director Tax Overview) shows period context in the summary header. On non-monthly setups: "Current Biweekly period: Apr 15-30, 2026" + an additional sub-line under "Collected" showing ISK attributable to the current active period specifically (vs. the existing calendar-month total).
- `TaxController::myTaxBreakdown` AJAX returns period-bound slice (`period_type` / `period_start` / `period_end` / `period_label` in response; legacy `month` key kept for backward compat).
- `getMyTaxBreakdownData` signature widened to `(array $characterIds, Carbon $start, Carbon $end)` — mining breakdown aligns with displayed period instead of calendar month.

**Period Switch Safeguard**
- New settings slots: `tax_rates.tax_calculation_period_pending` and `tax_rates.tax_calculation_period_effective_from`. Period-type changes queue instead of applying immediately — unless the admin checks a new "Apply immediately" override (intended for fresh installs).
- Effective date defaults to **day 3 of next month** for monthly/biweekly — lets the current scheme's day-2 previous-period calc complete before promotion. Prevents H2 data loss on biweekly → monthly switches.
- `TaxPeriodHelper::getPendingPeriodChange()` exposes the queued change; new partial `taxes/partials/_pending_period_switch_banner.blade.php` shows a yellow warning on every tax page while a switch is queued.
- Lazy promotion in `getConfiguredPeriodType()` — no cron needed; first tax-page load or calculate-taxes run on or after the effective date auto-promotes and logs the transition.

**Cleanups / Observability**
- `Cache::lock()` added to `mining-manager:generate-reports` and `mining-manager:update-extractions` (matches the 8 other commands that already had it).
- `Http::timeout(10)` added to the three previously-bare HTTP calls (Slack webhook + two Fuzzwork price GETs).
- Security badge on analytics systems table now uses 0.45 (CCP's actual high-sec threshold) instead of 0.5 — Tasabeshi et al. now correctly green.
- Log line on period promotion: `Mining Manager: Tax calculation period promoted biweekly → monthly (effective 2026-05-03, promoted on 2026-05-03)`.

### Fixed

- **Event tracking only counted 1 of 19 participants** — `EventTrackingService::updateEventTracking()` was comparing a DATE column against DateTime watermarks, silently excluding all rows after the first tick. Also dropped the self-defeating `last_updated` incremental watermark; method is now idempotent and runs the full event window every pass via `updateOrCreate`.
- **Events showed "Total Mined: 0 ISK"** — `event_participants.value_mined` column added (migration `2026_01_01_000004`) alongside the existing `quantity_mined`. Event Tracking Service now populates both from `mining_ledger.total_value`.
- **Event type didn't scope tax modifier to correct ore category** — added `EVENT_TYPE_ORE_CATEGORIES` constant on `MiningEvent` + `appliesToOreCategory()` helper. `mining_op` applies only to regular ore, `ice_mining` only to ice, etc. "Special Event" covers every currently-taxed category.
- **`character_infos.corporation_id` reads returned null** — latent bug since SeAT's 2019 schema change dropped that column in favor of `character_affiliations`. Fixed in `LedgerSummaryService::generateDailySummary` (was silently breaking guest-mining detection and corp-scoped event attribution), `EventMiningAggregator`, `MiningTax::getCorporationIdAttribute`, and `CharacterInfoService`.
- **Event charts displayed garbage (~92K ISK for a 3.87B event)** — three director/member dashboard charts computed event tax as `$event->total_mined × hardcoded 10% × modifier`, but `total_mined` is unit quantity not ISK. All three now read from the authoritative `event_discount_total` on daily summaries:
  - Director "Event Tax (12 Months)" chart
  - Member "Mining Income" chart `event_bonus` series
  - Events index "Total Value" KPI
  - My Events "Total Mined" / "Avg Per Event" stats
- **my-events.blade.php crashed with "Attempt to read property 'id' on null"** — the view used `auth()->user()->id` (SeAT user ID) as a character_id filter, so `$myParticipation` was usually null. Refactored to aggregate across all of the user's characters via `$characterIds` passed from the controller; rank computed across top participants by `character_id` match rather than a brittle `$p->id === $myParticipation->id`.
- **Events list showed "0 participants"** — `events/index.blade.php` used `$event->participants_count` (plural typo); column is `participant_count` (singular). Dropped the bogus `/ max_participants` suffix too (that column doesn't exist).
- **Retroactive daily-summary rebuilds showed zero event discount** — `getEventAttributionForLedgerRow` filtered candidate events to `status='active'` only, so rebuilding a past day's summary after the event had transitioned to `completed` found nothing. Now accepts `active` and `completed` (excludes `planned` and `cancelled`).
- **`event_discount_total` was zero for moon events — the actual root cause.** `LedgerSummaryService::getOreCategory()` returned generic strings (`'moon_ore'`, `'abyssal_ore'`, `'triglavian_ore'`) but `MiningEvent::EVENT_TYPE_ORE_CATEGORIES` (and `mining_ledger.ore_category`) use the specific-rarity values (`'moon_r4'`, `'moon_r8'`, ..., `'moon_r64'`, `'abyssal'`, `'triglavian'`). The ingestion commands (`ProcessMiningLedgerCommand`, `ImportCharacterMiningCommand`) already produced the correct specific values; only this view-layer helper drifted. Result: `MiningEvent::appliesToOreCategory('moon_ore')` always returned false, attribution lookup always returned null, and every daily summary had `event_discount_total = 0` regardless of event activity. Aligned the helper with the ingestion side. Verified on user's install: 31 daily summary rows now carry non-zero discounts totaling ~38.5M ISK across 19 miners for a single 48h moon event.
- **Calculate Taxes Event Tax column always showed 0** — the attribution prefetch map keyed on `$row->mining_date` via `sprintf '%s'`, but `EventMiningRecord` casts `mining_date` to Carbon (`'date'` cast). Carbon's `__toString()` emits `"YYYY-MM-DD HH:MM:SS"` while the entry-side lookup used `Carbon::parse($entry->date)->toDateString()` → `"YYYY-MM-DD"`. Keys never matched. Explicit `->format('Y-m-d')` on both sides now.
- **Wrongly-named migration file** — `2026_04_21_000001_add_value_tracking_to_events` renamed to `2026_01_01_000004_add_value_tracking_to_events` to match plugin's fixed-date-prefix + sequential-numbering convention. Also converted from anonymous class to named class (`AddValueTrackingToEvents`) matching the other 3 migrations.
- **Dead code paths removed**:
  - `ProcessMiningLedgerListener` (deprecated, never registered, never fired in SeAT v5)
  - Dead `character_infos.corporation_id` fallback branches in `MiningTax` + `CharacterInfoService` (column dropped in 2019, branches unreachable)

### Changed

- **Weekly tax calculation period removed.**

  *Why:* ISO weeks (Mon-Sun) don't align to calendar months. A week starting Apr 27 ends May 3, so the tax row covered mining from April 27-30 AND May 1-3. Three compounding problems followed: (1) straddling tax rows leaked accounting into the next month; (2) switching weekly → anything caused **double-tax** because the straddling row's May days overlapped with the first new-scheme row also covering May; (3) dashboard charts had to smear weekly row totals across adjacent months. Biweekly (1st-14th, 15th-end) covers the sub-monthly use case cleanly.

  *If your install was running weekly — what happens on upgrade:*
  1. **Auto-heal on first read.** The first tax-page load or `calculate-taxes` cron after the upgrade rewrites `tax_rates.tax_calculation_period` from `weekly` to `monthly` in the settings store, logging a warning: `Mining Manager: Auto-migrated tax_calculation_period from deprecated "weekly" to "monthly"...`. No admin action required.
  2. **Historical weekly rows preserved.** Existing `mining_taxes` rows with `period_type='weekly'` stay in the database forever. They remain visible in Tax History, Tax Details, My Taxes breakdown, and CSV exports — rendered with their original weekly labels (e.g. "Mar 3-9, 2026") via `MiningTax::formatted_period`.
  3. **No new weekly rows.** Going forward the plugin only writes `monthly` or `biweekly` rows.
  4. **Switching to biweekly** (if the admin prefers sub-monthly over monthly): open Settings → Tax Rates and change the dropdown. The change queues to day 3 of next month via the new period-switch safeguard (no collision with the auto-migrated monthly setting).

  *Defense in depth:* three layers of weekly coercion ensure no new weekly data can slip in:
  - Settings form validation rejects `weekly` (`in:monthly,biweekly`)
  - `SettingsManagerService::updateTaxRates` coerces `weekly` → `monthly` with a log warning if any caller bypasses the form
  - `TaxPeriodHelper::normaliseLegacyWeekly()` coerces `weekly` passed to internal methods (period bounds, calc-day checks, etc.)

  No schema migration required. `mining_taxes.period_type` is `string(20)`, not an enum.
- **Moon event corp filter semantics** now documented and uniform:
  - Miner's current corp must equal `event.corporation_id` for corp-scoped events (no miner-corp filter for global events)
  - Observer row corp (moon owner) is NOT required to match miner corp — a Corp-B miner at a Corp-A moon legitimately counts for a Corp-B event and for any global event, provided the moon row is in the source pool per `tax_selector`
  - Source pool for moon events follows `tax_selector`: `only_corp_moon_ore` → restrict to moon-owner corp's observers; `all_moon_ore` → any observer; `no_moon_ore` → no observer data
- **Non-moon event participation narrowed by `tax_selector`** — a gas event on an install with `tax_selector.gas=false` now produces no records (previously it silently tracked zero-tax activity). Event form warns on mismatch.
- **Event webhook includes ISK value mined** — previously moon-event notifications showed only quantity; now also reports ISK total where available.

### Known Limitations

- **Moon events stay day-level.** EVE's observer data is day-aggregated; the plugin cannot get sub-day precision on moon mining until CCP changes ESI. Documented in Help under Events → Time Granularity.
- **Non-moon events use SeAT fetch time**, not literal EVE mining time (character_minings doesn't carry the moment of mining). Good enough for events spanning several hours; noisy for sub-hour events.
- **Weekly removal does not delete historical rows.** They remain visible in Tax History and export reports forever (we don't touch released migrations).

### How it works

**ESI tells us WHAT is happening. The clock tells us WHEN to notify. `event_mining_records` tells us WHICH mining counts for which event.**

## [1.0.2] - Time-Based Moon Arrival Notifications

### Fixed
- **Moon arrival notifications silently missed when chunk arrived between cron ticks** -- The previous ESI-polling-based notification path was fragile. The import loop's `determineStatus()` would write `status='ready'` directly when `chunk_arrival_time` had passed, bypassing the transition-detection code that fired notifications. If ESI was stale, offline, or the cron timing was off, notifications were lost. Now decoupled from ESI entirely.

### Added
- **`mining-manager:check-extraction-arrivals` command** -- New lightweight cron running every minute. Pure time arithmetic, no ESI calls. Queries extractions whose stored `chunk_arrival_time` has passed and fires moon_arrival notifications. Idempotent via the `notification_sent` flag. Handles edge cases:
  - Arrivals between 2h ESI-poll ticks (fire within 60s of actual arrival)
  - ESI downtime (stored `chunk_arrival_time` is the source of truth)
  - Extractions imported directly as `'ready'` (notification catches up automatically)
  - Cron outages (backed-up arrivals fire when cron resumes)
- **`mining-manager:backfill-extraction-history` command** -- Reconstructs historical `moon_extraction_history` rows from EVE `MoonminingExtractionStarted` character notifications. When the plugin is installed on a corp that has months of pre-existing mining history, ESI only returns active/upcoming extractions; completed cycles can't be re-fetched. SeAT retains character notifications, though, so this command scans them, dedupes by `(structure_id, readyTime)`, matches each extraction to its corresponding fracture/cancel notification (manual via `MoonminingLaserFired`, auto via `MoonminingAutomaticFracture`, or `MoonminingExtractionCancelled`), computes actual mined values from `mining_ledger` where data exists, and inserts complete history rows. **Progress bars** for both the dedup pass (parsing YAML notifications) and the main processing pass (DB queries per extraction). Supports `--structure=ID` to scope to one structure, `--days=N` lookback window, `--dry-run` preview, and `--force` to recreate existing rows. Historical ISK prices are unknown so `estimated_value` fields are left NULL. Automatically invoked during `mining-manager:initialize` Phase 3 (historical backfill) when the user opts in.
- **Cancellation detection via EVE notifications** -- New `detectCancellations()` method on `MoonExtractionService` parses `MoonminingExtractionCancelled` character notifications (same pattern as existing fracture detection). When a director cancels an extraction in-game, the state system marks it `cancelled` within the next 2h poll cycle. The notification watchdog then skips it -- no false "Moon Chunk Ready" alert fires at the originally scheduled arrival time. `cancelled` is now a valid status alongside `extracting`, `ready`, `expired`. Runs automatically inside `update-extractions`. Follows the existing notification-parsing convention (`MoonminingLaserFired`, `MoonminingAutomaticFracture`, `MoonminingExtractionStarted`).
- **Architecture documentation** -- README and in-app Help docs now explain the two-system model:
  - State system (ESI-driven, every 2h): what EVE says is happening
  - Notification system (time-driven, every minute): when to notify
- **`--dry-run`, `--hours-back`, `--limit` flags** on the new command for testing and controlling dispatch volume.
- **Enhanced diagnostic logging** -- `updateExtractionStatuses()`, `sendMoonArrivalNotification()`, `sendMoonNotification()`, and `getMoonOwnerScopedWebhooks()` now emit structured `Log::info`/`Log::warning` entries at every decision point. Visible in SeAT Log Viewer (filter by Info level). Makes silent failures easy to diagnose.

### Changed
- **`sendMoonArrivalNotification()` now sets `notification_sent = true`** after successful dispatch, enforcing dedup across both entry points (old `updateExtractionStatuses` path and new `check-extraction-arrivals` cron). First caller wins, subsequent callers skip safely.
- **Archive command now archives cancelled extractions** -- previously `ArchiveOldExtractionsCommand` only archived `expired` and `fractured` statuses. Cancelled extractions (detected via `MoonminingExtractionCancelled` notification) accumulated in `moon_extractions` indefinitely because their originally planned `natural_decay_time` stayed in the future. Now handled via an OR branch: cancelled rows are archived 7 days after `updated_at` (the cancellation detection timestamp). Ensures `moon_extraction_history` is the single source of truth for past extractions regardless of final state.
- **Cancelled extractions display with a semantic badge** -- Moon show page now renders cancelled extractions with a dark badge and ban icon (`<i class="fas fa-ban">`) rather than falling through to the generic "warning" label.
- **Backfill command now correctly treats cancelled extractions as having zero mining** -- cancelled extractions never had a chunk to mine. If ledger activity exists in the cancelled extraction's time window, it belongs to a different (typically rescheduled) extraction. The backfill now sets `actual_mined_value`, `total_miners`, and `completion_percentage` to zero for cancelled rows regardless of what the ledger shows.
- **Moon show page history now unions both tables** -- previously the controller checked `moon_extraction_history` first and only fell back to `moon_extractions` if history was empty. Once any archived row existed, recently-expired extractions (still in `moon_extractions`, pending their 7-day archive cooldown) became invisible. The controller now queries both tables, dedupes by `chunk_arrival_time`, and merges into a single sorted list. Recently-terminal extractions appear immediately without waiting for archival. The 7-day archive cooldown is kept as-is to allow late ESI fracture data to settle.
- **"Value at Arrival" now correctly preserved and displayed** -- The `estimated_value_pre_arrival` column on `moon_extractions` (→ `estimated_value_at_arrival` on `moon_extraction_history`) was being overwritten every 12 hours by `RecalculateExtractionValuesCommand`, defeating its purpose as a historical snapshot. Three fixes:
  1. `CheckExtractionArrivalsCommand` (every-minute cron) now snapshots the current `estimated_value` into `estimated_value_pre_arrival` at the moment the chunk arrives — one-time, idempotent, only runs if the field is NULL. This locks in the arrival-time price ~60s after actual arrival.
  2. `RecalculateExtractionValuesCommand` now only updates `estimated_value_pre_arrival` for extractions whose `chunk_arrival_time` is still in the future. Once arrived, the snapshot is frozen.
  3. Moon show page history table column renamed from "Estimated Value" to "Value at Arrival" and now reads `estimated_value_at_arrival` (archive) / `estimated_value_pre_arrival` (pending archive) with fallback to `final_estimated_value` or N/A.
- **Completion % baseline fixed** -- was calculated against `estimated_value` (current running value, drifts with market) — now uses `estimated_value_pre_arrival` (locked at arrival) for historically accurate completion measurements. The chunk had a specific ISK value when it arrived; completion % now measures what fraction of THAT value was captured before despawn. Falls back to `estimated_value` for rows without arrival snapshots.
- **Fixed narrow mining window + cancelled-attribution bug in `calculateActualMined` helpers** -- three separate copies of this helper (backfill command, moon show controller, archive command) all had the same two issues: (1) searched only the 3-hour pre-fracture window instead of the full 72-hour mining lifecycle, missing most actual mining activity, (2) counted ledger activity for cancelled extractions as if they had been mined. All three now use a 72h window from `chunk_arrival_time`, query by `observer_id = structure_id` for precise attribution, and return zeros for cancelled rows.
- **Past Extractions (Archived) table — interactive DataTables + MOC scoping + Structure column** -- the archived history table on `/mining-manager/moon` is now filtered to Moon Owner Corporation only (other directors' private moons on shared SeAT installs no longer leak through). Added a Structure column showing station/refinery names (batch-loaded via `MoonExtraction::loadDisplayNames()` — no N+1). Table uses jQuery DataTables for client-side sorting (all columns, with numeric `data-order` attributes on dates/values/progress bars for correct sort semantics), full-text search across all columns, a Status filter dropdown (auto-populated from the visible badge text), and pagination (10/25/50/100/All). Default sort is chunk arrival descending.
- **`mining-manager:backfill-extraction-history` now filters by Moon Owner Corporation** -- resolves MOC from settings, pre-loads the set of structure IDs owned by that corp, and skips notifications for any other structure during the dedup pass. Rejects `--structure=ID` for structures not owned by MOC. Reports a count of skipped foreign-corp notifications. Fully dynamic — if MOC changes in Settings, next run uses the new value.

### How it works
**ESI tells us WHAT is happening. The clock tells us WHEN to notify.**

## [1.0.1] - Notification & Event Fixes

### Fixed
- **Ghost webhook / duplicate report notifications** -- Monthly report cron ran daily instead of monthly, generating identical reports every day and dispatching to all webhooks. Changed to day 9 of month (7 days after finalize-month for collection % to mature). Added dedup guard with `--force` override.
- **Moon arrival notifications silently never sent** -- Cron command had a duplicate status-transition method that bypassed the notification dispatcher. Extractions transitioned to "ready" but no Discord/Slack notification ever fired. Now delegates to the service's method which includes notification dispatch.
- **Events stuck in PLANNED status** -- No automatic status transitions existed. Events never moved from planned to active to completed unless manually clicked. Added auto-transition logic to the cron with event_started and event_completed notification dispatch.
- **Event location scope broken for constellation/region** -- Constellation and region-scoped events silently failed because the code compared a constellation/region ID directly against solar system IDs. Added spatial hierarchy resolution via mapDenormalize with 24h caching.
- **Role ping ignoring per-type settings** -- Both NotificationService and WebhookService had a legacy fallback that pinged the webhook's discord_role_id even when the per-type "Ping Role" toggle was OFF. Per-type settings are now authoritative in both dispatchers.
- **Manual report dispatch to wrong channel** -- Hidden webhook picker in report generation form silently submitted the first webhook ID. Removed the picker entirely; dispatch is now subscription-driven via webhook configuration.
- **Tax notification scoping** -- Tax notifications via NotificationService were dispatched to all enabled webhooks regardless of corporation. Now scoped to the Moon Owner / Tax Program Corporation, consistent with moon and theft notification scoping.
- **Wallet division showing hangar name** -- Payment instructions displayed hangar division name (e.g. "Handouts") instead of wallet division name (e.g. "Taxes and Bills") because the query didn't filter by `type='wallet'`.
- **Silent event notification failure** -- `sendBroadcast()` checked `general.corporation_id` which was often empty at global scope. Now uses `getTaxProgramCorporationId()` (reads `general.moon_owner_corporation_id`).

### Added
- **Auto tax code generation** -- Tax codes are now automatically generated when invoices are created. The manual `generate-tax-codes` command remains as a fallback.
- **`getTaxProgramCorporationId()` accessor** -- Single canonical method on SettingsManagerService for resolving the tax program / moon owner corporation. All legacy `general.corporation_id` fallback patterns consolidated.
- **`getMoonOwnerScopedWebhooks()` helper** -- Shared webhook filtering for moon, theft, and tax notifications. Ensures webhooks from other directors' corps on the same SeAT install are excluded.
- **Event location resolution on MiningEvent model** -- `getMatchingSystemIds()`, `applyLocationFilter()`, `matchesSystem()` methods resolve constellation/region scopes to system ID lists via mapDenormalize.
- **Audit logging for direct webhook dispatch** -- Moon, theft, and report notifications now log to `mining_notification_log` (previously only tax and event notifications were logged).
- **Report dedup guard** -- `GenerateReportsCommand` skips generation if a report for the same period+type already exists. Use `--force` to override.
- **`--force` flag on generate-reports** -- Allows intentional regeneration of existing reports.

### Changed
- **Event cron frequency** -- `mining-manager:update-events` changed from every 2 hours to every minute for timely status transitions and notifications.
- **Report cron frequency** -- `mining-manager:generate-reports` changed from daily to day 9 of month at 4:05 AM.
- **`generate-tax-codes` default scope** -- Without `--month`, now scans ALL unpaid taxes missing active codes instead of only the previous month.
- **Report "Send to Discord" UI** -- Removed webhook picker from both generate and show pages. Dispatch is now controlled entirely by webhook subscriptions (notify_report_generated flag). Shows informational list of subscribed webhooks.
- **Event notifications scope** -- Event notifications (created/started/completed) remain globally dispatched. All other notification types (moon/theft/tax) are scoped to the Moon Owner Corporation.

## [1.0.0] - Initial Release

### Initial Release

**Core Systems**
- Mining ledger processing with automated price lookups from multiple market data sources (SeAT, Fuzzwork, Janice, Manager Core)
- Daily summaries as single source of truth for all tax calculations
- Per-ore category tax rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian)
- Multi-corporation support with per-corp tax rates and tax selectors
- Guest mining detection with separate global tax rates (tied to Moon Owner Corporation)
- Event tax modifiers for mining operations (percentage-based discounts/surcharges)
- Tax code generation with wallet payment verification and auto-reconciliation
- Orphan moon ore reconciliation against Moon Owner Corp observer data

**Moon Mining**
- Moon extraction tracking with ore composition and estimated values
- Jackpot detection -- automatic (daily scan of mining data for +100% variant ores)
- Manual jackpot reporting -- members can report jackpots from arrived extractions
- Jackpot verification -- auto-detection verifies manual reports, marks unverified if no data found
- Moon chunk arrival and jackpot Discord/Slack webhook notifications
- Extraction calendar view, active extractions dashboard with auto-refresh
- Moon value calculator/simulator
- Ready-to-fracture and unstable extraction alerts

**Tax System**
- Corporation tax model: Moon Owner Corp observers for moon tax, per-corp rates for configured corps
- Guest miner tax rates in General Settings (global, tied to Moon Owner Corporation)
- 0% guest rate means actual zero tax (not fallback to corp rate)
- Tax calculation from daily summaries (Calculate button) or full regeneration (Recalculate button)
- Payment code generation with configurable prefix
- Tax code mixed-length support (6, 8, 10, or 12 characters) with automatic detection of all active lengths during wallet matching
- Configurable minimum tax amount with exempt/enforce behavior
- Wallet payment verification with tolerance matching and dismissed transaction tracking
- Manual payment entry with two modes: record payment (existing invoices with partial payment support) and manual entry (ad-hoc mid-period settlements for characters leaving corp)
- Tax exemption threshold for small miners
- Tax reminders, invoices, and overdue notifications via Discord/Slack
- Tax announcement notification for all members when new invoices are generated (no ISK amounts, links to My Taxes and How to Pay)

**Mining Events**
- Create mining events with participant tracking and leaderboards
- Tax modifier support (percentage discount/surcharge during events)
- Event lifecycle notifications (created, started, completed)

**Reports & Analytics**
- Daily, weekly, monthly reports with PDF, CSV, and JSON export
- Scheduled report generation with Discord/Slack webhook delivery
- Corporation dashboard with 12-month charts and statistics
- Mining leaderboards and per-character analytics
- Analytics data tables with corporation names, region names via SDE lookup
- Weekly activity heatmap with non-SeAT character name resolution via ESI/zKill
- Comparative analysis: period vs period, miner vs miner, system vs system, ore vs ore

**Notifications & Webhooks**
- Multiple webhook support -- each with independent event toggles
- Discord role pinging with personal vs broadcast notification modes
- Ping content options: show tax amount or general notice with link
- Individual/General scope labels on all notification types in settings UI
- 15 notification types: tax (generated, announcement, reminder, invoice, overdue), moon (arrival, jackpot), events (created, started, completed), theft (detected, critical, active, resolved), reports
- Supported channels: Discord webhooks and Slack (EVE Mail channel is not currently available)
- Unified notification testing panel in diagnostics with all 15 types

**Theft Detection**
- Detect unauthorized mining at corporation moons
- Severity classification (medium, high, critical)
- Active theft monitoring with activity tracking
- Incident management with resolution tracking

**Diagnostics**
- 15-tab diagnostic suite:
  - Test Data -- generate and manage test data
  - Price Provider -- test and compare price sources
  - Cache Health -- price cache status and staleness detection
  - System Validation -- verify configuration and dependencies
  - Settings Health -- audit settings for inconsistencies
  - Tax Trace -- daily summary inspection and live recalculation comparison
  - Data Integrity -- check for orphaned or inconsistent records
  - Valuation Test -- compare ore valuations across providers
  - System Status -- scheduler health and queue monitoring
  - Notification Testing -- unified panel with all 15 notification types and production-parity formatting
  - Moon Extractions -- debug extraction data, notifications, and fractured_at timestamps
  - Tax Pipeline -- trace the full tax calculation pipeline from ledger to invoice
  - Theft Detection -- inspect theft scan results and active monitoring
  - Event Lifecycle -- debug mining event state transitions and participant data
  - Analytics & Reports -- verify report generation and analytics data integrity

**Settings**
- Moon Owner Corporation configuration for moon tax scoping
- Per-corporation tax rates via Switch Corporation Context
- Tax selector (all moon ore / only corp moon ore / no moon ore + regular ore, ice, gas, abyssal, triglavian)
- Configurable price provider (SeAT, Fuzzwork, Janice, Manager Core)
- Payment settings (wallet division, match tolerance, grace period)
- Display settings (currency decimals, pagination, compact mode)

**Documentation**
- Built-in Help & Documentation page with comprehensive guides
- How to Pay Taxes (member guide)
- How to Collect Taxes (director guide)
- Corporation Tax Model explanation with flow table
- Webhooks & Notifications setup guide
- CLI commands reference

**Technical**
- First-time setup wizard (`mining-manager:initialize`) with settings verification, current month data population, and optional historical backfill
- 31 artisan commands with 21 automated scheduled tasks
- Data backup and restore commands (`mining-manager:backup-data`, `mining-manager:restore-data`)
- SeAT 5.x permission integration (4-tier: view, member, director, admin)
- Reprocessing calculator with batch support for compressed ores
- Full settings cache management with per-corporation context
