<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventHistory;
use MiningManager\Models\EventMoon;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\EventSignup;
use MiningManager\Services\Events\EventAccess;
use MiningManager\Services\Events\EventMiningAggregator;
use MiningManager\Services\Events\EventPeople;
use MiningManager\Services\Events\EventRefineries;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Carbon\Carbon;

class EventController extends Controller
{
    /**
     * Event management service
     *
     * @var EventManagementService
     */
    protected $eventService;

    /**
     * Notification service
     *
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Who may do what to an event.
     *
     * @var EventAccess
     */
    protected $access;

    /**
     * Constructor
     *
     * @param EventManagementService $eventService
     * @param NotificationService $notificationService
     * @param EventAccess $access
     */
    public function __construct(
        EventManagementService $eventService,
        NotificationService $notificationService,
        EventAccess $access
    ) {
        $this->eventService = $eventService;
        $this->notificationService = $notificationService;
        $this->access = $access;
    }

    /**
     * Display all mining events
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags();
        if (!($features['enable_events'] ?? true)) {
            return redirect()->route('mining-manager.dashboard.index')
                ->with('warning', 'This feature is currently disabled. Enable it in Settings > Features.');
        }

        $status = $request->input('status', 'all');
        $corporationId = $request->input('corporation_id');

        $query = MiningEvent::with('creator');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Corporation filter - filter events by participants' corporation
        if ($corporationId) {
            $query->whereHas('participants', function($q) use ($corporationId) {
                $q->whereIn('character_id', function($subQuery) use ($corporationId) {
                    $subQuery->select('character_id')
                        ->from('character_affiliations')
                        ->where('corporation_id', $corporationId);
                });
            });
        }

        $events = $query->orderBy('start_time', 'desc')->paginate(20);

        // Who cancelled each cancelled event on the page, from its history.
        $cancelledBy = [];
        $cancelledIds = $events->getCollection()->where('status', MiningEvent::STATUS_CANCELLED)->pluck('id')->all();
        if ($cancelledIds && MiningEvent::hasGovernance()) {
            $cancelledBy = EventHistory::with('user')
                ->whereIn('event_id', $cancelledIds)
                ->where('action', EventHistory::CANCELLED)
                ->orderByDesc('id')
                ->get()
                ->unique('event_id')
                ->mapWithKeys(fn ($entry) => [$entry->event_id => $entry->user->name ?? null])
                ->all();
        }
        $access = $this->access;

        // Get corporations with event participation
        $corporationIds = DB::table('character_affiliations')
            ->whereIn('character_id', function($query) {
                $query->select('character_id')
                    ->from('event_participants')
                    ->distinct();
            })
            ->distinct()
            ->pluck('corporation_id');

        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corporationIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        // Calculate event statistics for summary boxes.
        // total_value must sum total_mined_value (ISK) — total_mined is the
        // quantity in units and would display as nonsense when the view labels
        // the number as ISK.
        $stats = [
            'active' => MiningEvent::where('status', 'active')->count(),
            'upcoming' => MiningEvent::where('status', 'planned')
                ->where('start_time', '<=', now()->addDays(7))
                ->where('start_time', '>', now())
                ->count(),
            'participants' => DB::table('event_participants')->distinct('character_id')->count('character_id'),
            'total_value' => MiningEvent::whereMonth('start_time', now()->month)
                ->whereYear('start_time', now()->year)
                ->sum('total_mined_value'),
        ];

        return view('mining-manager::events.index', compact('events', 'status', 'corporationId', 'corporations', 'stats', 'cancelledBy', 'access'));
    }

    /**
     * Show the form for creating a new event
     *
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags();
        if (!($features['enable_events'] ?? true) || !($features['allow_event_creation'] ?? true)) {
            return redirect()->route('mining-manager.dashboard.index')
                ->with('warning', 'Event creation is currently disabled. Enable it in Settings > Features.');
        }

        // Get the dropdown corp set: corps with MM settings + the moon
        // owner corp (always included). Loading the entire
        // `corporation_infos` table would mean that on a multi-corp shared
        // SeAT install hundreds of unrelated corps appear in the dropdown —
        // UI bloat, and an admin could pick a corp that has nothing to do
        // with this MM install. This scope matches what operators can
        // actually meaningfully assign events to.
        $corporations = $this->getEventDropdownCorporations();

        $taxCompat = $this->buildTaxCompatibilityMap();
        $refineries = app(EventRefineries::class)->choices();
        $prefill = $this->prefillFromChunk($request, $refineries);

        $limits = $this->formLimits();

        return view('mining-manager::events.create', compact('corporations', 'taxCompat', 'refineries', 'prefill', 'limits'));
    }

    /**
     * What the form starts with when it is opened from a chunk: a moon event
     * on that refinery, over the days its belt can last.
     *
     * @param array<int, array<string, mixed>> $refineries
     * @return array<string, mixed>
     */
    private function prefillFromChunk(Request $request, array $refineries): array
    {
        $structureId = (int) $request->query('refinery');
        $refinery = collect($refineries)->firstWhere('structure_id', $structureId);

        if (!$structureId || !$refinery) {
            return [];
        }

        $chunks = collect($refinery['chunks']);
        $chunk = $chunks->firstWhere('arrival_day', (string) $request->query('arrival'))
            ?? $chunks->first(fn ($chunk) => $chunk['gone'] >= Carbon::now()->toIso8601String());

        return [
            'type' => 'moon_extraction',
            'name' => ($refinery['moon'] ?? $refinery['name']) . ' chunk',
            'refineries' => [$structureId],
            'start_day' => $chunk['arrival_day'] ?? null,
            'end_day' => $chunk['gone_day'] ?? null,
        ];
    }

    /**
     * Check the event form and turn it into the event's attributes and the
     * refineries it is narrowed to.
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function eventFromForm(Request $request, ?MiningEvent $event = null): array
    {
        $type = (string) $request->input('type');
        $moon = $type === 'moon_extraction';

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string|in:' . implode(',', array_keys(MiningEvent::EVENT_TYPES)),
            'start_day' => $moon ? 'required|date_format:Y-m-d' : 'nullable',
            'end_day' => $moon ? 'nullable|date_format:Y-m-d|after_or_equal:start_day' : 'nullable',
            'start_time' => $moon ? 'nullable' : 'required|date',
            'end_time' => $moon ? 'nullable' : 'nullable|date|after:start_time',
            // Not sent when refineries narrow the event, which leaves its
            // location fields off.
            'location_scope' => 'nullable|string|in:any,system,constellation,region',
            'solar_system_id' => 'nullable|integer',
            'refineries' => 'nullable|array',
            'refineries.*' => 'integer',
            'ore_categories' => 'nullable|array',
            'ore_categories.*' => 'string',
            'audience' => 'required|string',
            'participation' => 'required|in:' . MiningEvent::PARTICIPATION_EVERYONE . ',' . MiningEvent::PARTICIPATION_SIGNED_UP,
            'tax_modifier' => 'required|integer|min:-100|max:100',
            'reason' => 'nullable|string|max:500',
        ]);

        $errors = [];

        // Narrowed to refineries, a moon event ignores its location.
        $refineries = $moon ? array_values(array_unique(array_map('intval', (array) $request->input('refineries', [])))) : [];
        if ($refineries) {
            $known = array_column(app(EventRefineries::class)->choices($event ? $event->refineryIds() : []), 'structure_id');
            if (array_diff($refineries, $known)) {
                $errors['refineries'] = 'Pick refineries from the list. Only our own refineries can be counted.';
            }
        }

        // The location must be the kind of place the scope says: a
        // constellation or region id is not a solar system.
        $scope = $refineries ? 'any' : (string) $request->input('location_scope', 'any');
        $locationId = $scope !== 'any' ? (int) $request->input('solar_system_id') : null;
        if ($scope !== 'any') {
            $group = ['system' => 5, 'constellation' => 4, 'region' => 3][$scope];
            if (!$locationId || !MapDenormalize::where('itemID', $locationId)->where('groupID', $group)->exists()) {
                $errors['solar_system_id'] = "Pick a {$scope} from the list.";
            }
        }

        $audience = (string) $request->input('audience');
        $corporationId = null;
        if (ctype_digit($audience)) {
            $corporationId = (int) $audience;
            $allowed = $this->getEventDropdownCorporations((int) ($event->corporation_id ?? 0))->pluck('corporation_id')->map(fn ($id) => (int) $id)->all();
            if (!in_array($corporationId, $allowed, true)) {
                $errors['audience'] = 'Pick one of the corporations on the list.';
            }
        } elseif (!in_array($audience, ['everyone', 'members'], true)) {
            $errors['audience'] = 'Pick who the event is for.';
        }

        // Ore narrowed within what the type covers; all of it, or a type
        // with no choice, is stored as no narrowing.
        $covered = MiningEvent::EVENT_TYPE_ORE_CATEGORIES[$type] ?? [];
        $categories = null;
        if (in_array($type, ['moon_extraction', 'special'], true) && $request->boolean('ore_categories_shown')) {
            $picked = array_values(array_intersect($covered, (array) $request->input('ore_categories', [])));
            if ($picked === []) {
                $errors['ore_categories'] = 'Pick at least one kind of ore for the event to count.';
            } elseif (count($picked) < count($covered)) {
                $categories = $picked;
            }
        }

        if ($moon) {
            $start = Carbon::createFromFormat('Y-m-d', $request->input('start_day'))->startOfDay();
            $end = $request->filled('end_day') ? Carbon::createFromFormat('Y-m-d', $request->input('end_day'))->endOfDay() : null;
        } else {
            $start = Carbon::parse($request->input('start_time'));
            $end = $request->filled('end_time') ? Carbon::parse($request->input('end_time')) : null;
        }

        $errors += $this->checkWhen($request, $event, $start, $end, $moon);

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        $attributes = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'location_scope' => $scope,
            'solar_system_id' => $locationId,
            'tax_modifier' => (int) $request->input('tax_modifier'),
            'corporation_id' => $corporationId,
        ];

        if (MiningEvent::hasRules()) {
            $attributes += [
                'participation' => $request->input('participation'),
                'members_only' => $audience === 'members',
                'ore_categories' => $categories,
            ];
        }

        return [$attributes, $refineries];
    }

    /**
     * What the event's days allow. Nothing already billed: a day on an issued
     * bill keeps what it was billed. Days that have passed only from a
     * director or an admin, for everyone who mined, with a reason, and not
     * so close to a bill run that the event could not apply before it.
     *
     * @return array<string, string> errors by field
     */
    private function checkWhen(Request $request, ?MiningEvent $event, Carbon $start, ?Carbon $end, bool $moon): array
    {
        if (!MiningEvent::hasGovernance()) {
            return [];
        }

        $errors = [];
        $startField = $moon ? 'start_day' : 'start_time';
        $endField = $moon ? 'end_day' : 'end_time';
        $past = $end !== null && $end->lte(Carbon::now());
        $pastEvent = $past && ($event === null || $event->status === MiningEvent::STATUS_HELD);

        // An event already reaching back that far keeps its days as long as
        // its start is not moved.
        $earliest = $this->access->earliestFirstDay();
        if ($earliest !== null && $start->lt($earliest) && ($event === null || !$start->eq($event->start_time))) {
            $errors[$startField] = trans('mining-manager::events.before_billed', [
                'last' => $earliest->copy()->subDay()->format('D j M'),
                'first' => $earliest->format('D j M'),
            ]);
        }

        if ($pastEvent && $request->input('participation') === MiningEvent::PARTICIPATION_SIGNED_UP) {
            $errors['participation'] = trans('mining-manager::events.past_no_signups');
        }

        if ($event === null && $past) {
            if (trim((string) $request->input('reason')) === '') {
                $errors['reason'] = trans('mining-manager::events.reason_needed_past');
            }
            if ($this->access->holdUntil() === null) {
                $errors[$startField] = trans('mining-manager::events.too_close_to_bill_run', ['run' => $this->access->nextBillRun()->format('D j M H:i')]);
            }
            if (!$this->access->isDirector(auth()->user())) {
                $errors[$startField] = trans('mining-manager::events.past_directors_only');
            }
        }

        if ($event !== null && $event->status === MiningEvent::STATUS_HELD && !$past) {
            $errors[$endField] = trans('mining-manager::events.held_stays_past');
        } elseif ($event !== null && $past && !$event->hasStarted()) {
            // Days that have passed are a past event's, held before they
            // count, not a planned one's moved back.
            $errors[$endField] = trans('mining-manager::events.make_past_instead');
        }

        return $errors;
    }

    /**
     * What the event form needs to know of the limits, so it can show them
     * before anyone saves.
     *
     * @return array<string, mixed>
     */
    private function formLimits(?MiningEvent $event = null): array
    {
        $governed = MiningEvent::hasGovernance();
        $earliest = $governed ? $this->access->earliestFirstDay() : null;

        return [
            'governed' => $governed,
            'creating' => $event === null,
            'held' => $event !== null && $event->status === MiningEvent::STATUS_HELD,
            'earliest_day' => $earliest?->toDateString(),
            'last_billed' => $earliest?->copy()->subDay()->format('D j M'),
            'earliest_label' => $earliest?->format('D j M'),
            // An event already reaching back further keeps its start.
            'enforce_earliest' => $earliest !== null && ($event === null || $event->start_time->gte($earliest)),
            'can_past' => $this->access->isDirector(auth()->user()),
            'hold_minutes' => $governed ? $this->access->settings()['past_hold_minutes'] : 0,
            'today' => Carbon::now()->toDateString(),
        ];
    }

    /**
     * Put the event's refineries in line with the form, and say whether they
     * changed, so the event can be gathered again.
     *
     * @param array<int, int> $structureIds
     */
    private function syncRefineries(MiningEvent $event, array $structureIds): bool
    {
        if (!MiningEvent::hasRules()) {
            return false;
        }

        $current = $event->refineryIds();
        sort($current);
        sort($structureIds);

        if ($current === $structureIds) {
            return false;
        }

        $event->moons()->whereNotIn('structure_id', $structureIds ?: [0])->delete();
        $moonIds = app(\MiningManager\Services\Moon\RefineryService::class)->moonIdsForStructures($structureIds);

        foreach (array_diff($structureIds, $current) as $structureId) {
            EventMoon::create([
                'event_id' => $event->id,
                'structure_id' => $structureId,
                'moon_id' => $moonIds[$structureId] ?? null,
            ]);
        }

        return true;
    }

    /**
     * Store a newly created event
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        [$attributes, $refineries] = $this->eventFromForm($request);
        $user = auth()->user();

        // Days that have passed: held, so it can still be changed or deleted
        // before its discount goes on them.
        $status = MiningEvent::STATUS_PLANNED;
        if (MiningEvent::hasGovernance() && $attributes['end_time'] !== null && $attributes['end_time']->lte(Carbon::now())) {
            $status = MiningEvent::STATUS_HELD;
            $attributes['applies_at'] = $this->access->holdUntil();
        }

        try {
            $event = DB::transaction(function () use ($attributes, $refineries, $status, $request) {
                $event = MiningEvent::create($attributes + [
                    'status' => $status,
                    'created_by' => auth()->user()->id,
                ]);
                $this->syncRefineries($event, $refineries);
                EventHistory::record($event, EventHistory::CREATED, auth()->id(), $event->snapshot(), $request->input('reason'));

                return $event;
            });

            if ($status === MiningEvent::STATUS_HELD) {
                $this->notifyReview($event, 'held', $user, $request->input('reason'));

                return redirect()->route('mining-manager.events.show', $event->id)
                    ->with('success', trans('mining-manager::events.held_created', ['time' => $event->applies_at->format('D j M H:i')]));
            }

            // Send notifications if requested
            if ($request->input('send_notifications')) {
                try {
                    $this->notificationService->sendEventCreated($event);
                } catch (\Exception $e) {
                    \Log::warning('EventController: Failed to send event created notification', [
                        'event_id' => $event->id,
                        'error' => $e->getMessage()
                    ]);
                }

            }

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Mining event created successfully');
        } catch (\Exception $e) {
            \Log::error('EventController: Error creating event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error creating event: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified event
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        try {
            $event = MiningEvent::with('creator')->findOrFail($id);
            $user = auth()->user();

            if (!$this->access->canSee($user, $event)) {
                return redirect()->route('mining-manager.events.index')
                    ->with('error', trans('mining-manager::events.not_announced_yet'));
            }

            $eventPeople = app(EventPeople::class);
            $people = $eventPeople->people($event);
            $byDay = $eventPeople->byDay($event);
            $byRefinery = $eventPeople->byRefinery($event);
            $reportingUntil = $eventPeople->stillReportingUntil($event);

            // The refineries the event is narrowed to, or for one that is
            // not, the ones its mining came from.
            $refineries = app(EventRefineries::class)->forEvent(
                $event->refineryIds() ?: array_keys($byRefinery),
                $event->start_time,
                $event->end_time
            );

            $mined = array_filter($people, fn ($person) => $person['characters'] !== []);
            $totals = [
                'people' => count($mined),
                'characters' => array_sum(array_map(fn ($person) => count($person['characters']), $people)),
                'signups' => count(array_filter($people, fn ($person) => in_array($person['status'], [EventPeople::SIGNED_UP, EventPeople::SIGNED_UP_WAITING], true))),
                'value' => array_sum(array_column($people, 'value')),
                'qualifying' => array_sum(array_column($people, 'qualifying')),
                'discount' => array_sum(array_column($people, 'discount')),
            ];
            $signedUp = $event->isSignedUp($user);

            $can = [
                'edit' => $this->access->canEdit($user, $event),
                'cancel' => $this->access->canCancel($user, $event),
                'restore' => $this->access->canRestore($user, $event),
                'approve' => $this->access->canApprove($user, $event),
                'delete' => $this->access->canDelete($user, $event),
                'update_data' => $this->access->isDirector($user),
            ];
            $history = MiningEvent::hasGovernance()
                ? $this->describeHistory($event->history()->with('user')->limit(200)->get())
                : [];

            return view('mining-manager::events.show', compact('event', 'people', 'byDay', 'byRefinery', 'refineries', 'reportingUntil', 'totals', 'signedUp', 'can', 'history'));
        } catch (\Exception $e) {
            \Log::error('EventController: Error showing event', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('mining-manager.events.index')
                ->with('error', 'Error loading event: ' . $e->getMessage());
        }
    }

    /**
     * Show the form for editing the event
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function edit($id)
    {
        $event = MiningEvent::findOrFail($id);

        if (!$this->access->canEdit(auth()->user(), $event)) {
            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('error', trans('mining-manager::events.cannot_edit'));
        }

        // Same scoped dropdown as create() — corps with MM settings +
        // moon owner. Plus, defensively, the event's currently-assigned
        // corp_id (if any) so an event tied to a corp that's no longer
        // in the dropdown set still renders correctly.
        $corporations = $this->getEventDropdownCorporations((int) ($event->corporation_id ?? 0));

        $taxCompat = $this->buildTaxCompatibilityMap();
        $refineries = app(EventRefineries::class)->choices($event->refineryIds());
        $canDelete = $this->access->canDelete(auth()->user(), $event);
        $limits = $this->formLimits($event);

        return view('mining-manager::events.edit', compact('event', 'corporations', 'taxCompat', 'refineries', 'canDelete', 'limits'));
    }

    /**
     * Build the corporation dropdown for event create/edit forms.
     *
     * Scope: the configured corporations, the Moon Owner always among
     * them. Optionally include a specific corp by ID (used by edit() so
     * an event tied to a corp that's no longer in the active set still
     * renders its current value correctly).
     *
     * Without this filter the create/edit forms load the entire
     * `corporation_infos` table — on a shared SeAT install with many
     * historical corps the dropdown can run to hundreds of entries.
     *
     * @param int $alwaysIncludeCorpId  Optional corp id to include even
     *                                  if it's not in the active set
     * @return \Illuminate\Support\Collection
     */
    protected function getEventDropdownCorporations(int $alwaysIncludeCorpId = 0)
    {
        // The configured corporations, the Moon Owner always among them.
        $corpIds = collect(app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getHomeCorporationIds());

        // Optional pin (edit form's currently-assigned corp).
        if ($alwaysIncludeCorpId > 0) {
            $corpIds->push($alwaysIncludeCorpId);
        }

        $corpIds = $corpIds->filter()->unique()->values();

        if ($corpIds->isEmpty()) {
            return collect();
        }

        return DB::table('corporation_infos')
            ->whereIn('corporation_id', $corpIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();
    }

    /**
     * Update the specified event
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $event = MiningEvent::findOrFail($id);

        if (!$this->access->canEdit(auth()->user(), $event)) {
            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('error', trans('mining-manager::events.cannot_edit'));
        }

        [$attributes, $refineries] = $this->eventFromForm($request, $event);

        // A change to an event under way moves what people already get, so
        // whoever makes it says why.
        if ($event->hasStarted() && $event->isAnnounced() && trim((string) $request->input('reason')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason' => trans('mining-manager::events.reason_needed_started'),
            ]);
        }

        $before = $event->snapshot();

        try {
            DB::transaction(function () use ($event, $attributes, $refineries, $before, $request) {
                // Refineries first, so a scope edit gathers the event with
                // them. Refineries alone changing is not a change to the
                // event row, so it gathers the event itself.
                $refineriesChanged = $this->syncRefineries($event, $refineries);
                $event->update($attributes);

                if ($refineriesChanged && !$event->wasChanged(MiningEvent::SCOPE_AFFECTING_FIELDS)) {
                    app(EventMiningAggregator::class)->aggregate($event, true);
                }

                $changes = MiningEvent::changesBetween($before, $event->snapshot());
                if ($changes !== []) {
                    EventHistory::record($event, EventHistory::EDITED, auth()->id(), $changes, $request->input('reason'));
                }
            });

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event updated successfully');
        } catch (\Exception $e) {
            \Log::error('EventController: Error updating event', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating event: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified event
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);
            $user = auth()->user();

            if (!$this->access->canDelete($user, $event)) {
                return redirect()->route('mining-manager.events.show', $event->id)
                    ->with('error', trans('mining-manager::events.cannot_delete'));
            }

            // Taken before the delete, which takes the refineries with it.
            $snapshot = $event->snapshot() + ['status' => $event->status];
            $event->delete();
            EventHistory::record($event, EventHistory::DELETED, $user->id, $snapshot);
            $this->notifyReview($event, 'deleted', $user);

            return redirect()->route('mining-manager.events.index')
                ->with('success', 'Event deleted successfully');
        } catch (\Exception $e) {
            \Log::error('EventController: Error deleting event', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()
                ->with('error', 'Error deleting event: ' . $e->getMessage());
        }
    }

    /**
     * Cancel an event. Its discount comes off every day not billed yet, and
     * whoever cancels it says why.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel(Request $request, $id)
    {
        $event = MiningEvent::findOrFail($id);
        $user = auth()->user();

        if (!$this->access->canCancel($user, $event)) {
            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('error', trans('mining-manager::events.cannot_cancel'));
        }

        $request->validate(['reason' => 'required|string|max:500']);

        $from = $event->status;
        $event->update(['status' => MiningEvent::STATUS_CANCELLED]);
        EventHistory::record($event, EventHistory::CANCELLED, $user->id, ['status' => [$from, MiningEvent::STATUS_CANCELLED]], $request->input('reason'));
        $this->notifyReview($event, 'cancelled', $user, $request->input('reason'));

        return redirect()->route('mining-manager.events.show', $event->id)
            ->with('success', trans('mining-manager::events.cancelled_done'));
    }

    /**
     * Bring a cancelled event back, so its discount goes back on the days not
     * billed yet.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(Request $request, $id)
    {
        $event = MiningEvent::findOrFail($id);
        $user = auth()->user();

        if (!$this->access->canRestore($user, $event)) {
            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('error', trans('mining-manager::events.cannot_restore'));
        }

        $request->validate(['reason' => 'nullable|string|max:500']);

        $status = $this->statusToRestore($event);
        $attributes = ['status' => $status];

        // Held again, from now: whoever brings it back is changing what it
        // will do, and the other directors get their look again.
        if ($status === MiningEvent::STATUS_HELD) {
            $until = $this->access->holdUntil();
            if ($until === null) {
                return redirect()->route('mining-manager.events.show', $event->id)
                    ->with('error', trans('mining-manager::events.too_close_to_bill_run', ['run' => $this->access->nextBillRun()->format('D j M H:i')]));
            }
            $attributes['applies_at'] = $until;
        }

        $event->update($attributes);
        EventHistory::record($event, EventHistory::RESTORED, $user->id, ['status' => [MiningEvent::STATUS_CANCELLED, $status]], $request->input('reason'));
        $this->notifyReview($event, 'restored', $user, $request->input('reason'));

        return redirect()->route('mining-manager.events.show', $event->id)
            ->with('success', trans('mining-manager::events.restored_done'));
    }

    /**
     * What a cancelled event comes back as: still waiting, when it was held
     * or waiting for approval; otherwise what its days make it now.
     */
    private function statusToRestore(MiningEvent $event): string
    {
        $before = null;

        if (MiningEvent::hasGovernance()) {
            $cancelled = EventHistory::where('event_id', $event->id)
                ->where('action', EventHistory::CANCELLED)
                ->orderByDesc('id')
                ->first();
            $before = $cancelled->changes['status'][0] ?? null;
        }

        if (in_array($before, [MiningEvent::STATUS_HELD, MiningEvent::STATUS_AWAITING_APPROVAL], true)) {
            return $before;
        }

        if (!$event->hasStarted()) {
            return MiningEvent::STATUS_PLANNED;
        }

        return $event->isPast() ? MiningEvent::STATUS_COMPLETED : MiningEvent::STATUS_ACTIVE;
    }

    /**
     * Tell the Event Review webhooks. A message that fails to go out is
     * logged; what was done stands.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    private function notifyReview(MiningEvent $event, string $kind, $user = null, ?string $reason = null): void
    {
        try {
            $this->notificationService->sendEventReview($event, $kind, [
                'by' => $user->name ?? null,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('EventController: could not send the Event Review message', [
                'event_id' => $event->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The event's history as its page shows it: when, by whom, what and why,
     * and for anything that changed the event, each field before and after.
     *
     * @param \Illuminate\Support\Collection<int, EventHistory> $history
     * @return array<int, array{when: \Carbon\Carbon|null, who: string, action: string, reason: ?string, changes: array<int, array{field: string, before: string, after: string}>}>
     */
    private function describeHistory($history): array
    {
        $changed = $history->reject(fn ($entry) => in_array($entry->action, [EventHistory::CREATED, EventHistory::DELETED], true));

        // Names for the refineries, places and corporations the changes name.
        $structureIds = [];
        $placeIds = [];
        $corporationIds = [];
        foreach ($changed as $entry) {
            foreach ((array) $entry->changes as $field => $pair) {
                foreach ((array) $pair as $value) {
                    if ($field === 'refineries') {
                        $structureIds = array_merge($structureIds, array_map('intval', (array) $value));
                    } elseif ($field === 'solar_system_id' && $value) {
                        $placeIds[] = (int) $value;
                    } elseif ($field === 'corporation_id' && $value) {
                        $corporationIds[] = (int) $value;
                    }
                }
            }
        }

        $structures = $structureIds ? DB::table('universe_structures')->whereIn('structure_id', array_unique($structureIds))->pluck('name', 'structure_id')->all() : [];
        $places = $placeIds ? MapDenormalize::whereIn('itemID', array_unique($placeIds))->pluck('itemName', 'itemID')->all() : [];
        $corporations = $corporationIds ? DB::table('corporation_infos')->whereIn('corporation_id', array_unique($corporationIds))->pluck('name', 'corporation_id')->all() : [];

        $none = trans('mining-manager::events.history_none');
        $show = function (string $field, $value) use ($structures, $places, $corporations, $none): string {
            if ($value === null || $value === '' || $value === []) {
                return match ($field) {
                    'refineries' => trans('mining-manager::events.history_any_refinery'),
                    'ore_categories' => trans('mining-manager::events.history_all_ore'),
                    default => $none,
                };
            }

            return match ($field) {
                'type' => MiningEvent::EVENT_TYPES[$value] ?? (string) $value,
                'location_scope' => MiningEvent::LOCATION_SCOPES[$value] ?? (string) $value,
                'solar_system_id' => $places[$value] ?? (string) $value,
                'refineries' => implode(', ', array_map(fn ($id) => $structures[$id] ?? (string) $id, (array) $value)),
                'ore_categories' => implode(', ', array_map(fn ($category) => MiningEvent::ORE_CATEGORY_LABELS[$category] ?? $category, (array) $value)),
                'corporation_id' => $corporations[$value] ?? (string) $value,
                'members_only' => trans('mining-manager::events.' . ($value ? 'yes' : 'no')),
                'participation' => trans('mining-manager::events.' . ($value === MiningEvent::PARTICIPATION_SIGNED_UP ? 'participation_signed_up' : 'participation_everyone')),
                'tax_modifier' => MiningEvent::TAX_MODIFIER_LABELS[(int) $value] ?? ((int) $value > 0 ? '+' : '') . (int) $value . '%',
                'status' => trans('mining-manager::events.' . $value),
                'description' => \Illuminate\Support\Str::limit((string) $value, 120),
                default => (string) $value,
            };
        };

        return $history->map(function ($entry) use ($show) {
            $changes = [];
            if (!in_array($entry->action, [EventHistory::CREATED, EventHistory::DELETED], true)) {
                foreach ((array) $entry->changes as $field => $pair) {
                    if (!is_array($pair) || count($pair) !== 2) {
                        continue;
                    }
                    $changes[] = [
                        'field' => trans('mining-manager::events.history_field_' . $field),
                        'before' => $show($field, $pair[0]),
                        'after' => $show($field, $pair[1]),
                    ];
                }
            }

            return [
                'when' => $entry->created_at,
                'who' => $entry->user_id === null
                    ? trans('mining-manager::events.history_by_plugin')
                    : ($entry->user->name ?? trans('mining-manager::events.unknown')),
                'action' => trans('mining-manager::events.history_' . $entry->action),
                'reason' => $entry->reason,
                'changes' => $changes,
            ];
        })->values()->all();
    }

    /**
     * Start an event
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function start($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);

            // ATOMIC CLAIM — flip planned→active in a single UPDATE WHERE.
            // The cron path (EventManagementService::updateEventStatuses) uses
            // the same pattern, so when both fire near-simultaneously on the
            // same event only one wins the flip and only one notification
            // dispatches. start_time is locked to the moment of the manual
            // click (existing behaviour preserved).
            $claimed = MiningEvent::where('id', $event->id)
                ->where('status', 'planned')
                ->update([
                    'status' => 'active',
                    'start_time' => Carbon::now(),
                ]);

            if ($claimed === 0) {
                $event->refresh();
                if ($event->status === 'active') {
                    return redirect()->route('mining-manager.events.show', $event->id)
                        ->with('info', 'Event was already started.');
                }
                return redirect()->back()
                    ->with('error', "Cannot start an event with status '{$event->status}'.");
            }

            $event->refresh();
            EventHistory::record($event, EventHistory::STARTED, auth()->id());

            // Send event started notification
            try {
                $this->notificationService->sendEventStarted($event);
            } catch (\Exception $e) {
                \Log::warning('EventController: Failed to send event started notification', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage()
                ]);
            }

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event started successfully');
        } catch (\Exception $e) {
            \Log::error('EventController: Error starting event', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'Error starting event: ' . $e->getMessage());
        }
    }

    /**
     * Complete an event
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function complete($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);

            // Update event data one final time before completing
            $this->eventService->updateEventData($event);

            // ATOMIC CLAIM — same pattern as start(). Only the path that
            // flips active→completed dispatches event_completed.
            $claimed = MiningEvent::where('id', $event->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'completed',
                    'end_time' => Carbon::now(),
                ]);

            if ($claimed === 0) {
                $event->refresh();
                if ($event->status === 'completed') {
                    return redirect()->route('mining-manager.events.show', $event->id)
                        ->with('info', 'Event was already completed.');
                }
                return redirect()->back()
                    ->with('error', "Cannot complete an event with status '{$event->status}'.");
            }

            // Refresh the event to get updated totals
            $event->refresh();
            EventHistory::record($event, EventHistory::COMPLETED, auth()->id());

            // Send event completed notification
            try {
                $this->notificationService->sendEventCompleted($event);
            } catch (\Exception $e) {
                \Log::warning('EventController: Failed to send event completed notification', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage()
                ]);
            }

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event completed successfully. Tax modifier: ' . $event->getTaxModifierLabel());
        } catch (\Exception $e) {
            \Log::error('EventController: Error completing event', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'Error completing event: ' . $e->getMessage());
        }
    }

    /**
     * Display active events
     *
     * @return \Illuminate\View\View
     */
    public function active()
    {
        // FIXED: Changed variable name from $events to $activeEvents
        // to match what the view expects
        $activeEvents = MiningEvent::where('status', 'active')
            ->with(['participants.character', 'creator'])
            ->orderBy('start_time', 'desc')
            ->get();
    
        return view('mining-manager::events.active', compact('activeEvents'));
    }

    /**
     * Display calendar view of events
     *
     * @return \Illuminate\View\View
     */
    public function calendar()
    {
        $events = MiningEvent::with('creator', 'participants')
            ->where('status', '!=', 'cancelled')
            ->get();

        $upcomingEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'planned')
            ->where('start_time', '>', now())
            ->orderBy('start_time', 'asc')
            ->limit(10)
            ->get();

        // Format events for FullCalendar to avoid Blade compilation issues
        $formattedEvents = $events->map(function($event) {
            return [
                'id' => $event->id,
                'title' => $event->name,
                'start' => $event->start_time->toIso8601String(),
                'end' => $event->end_time ? $event->end_time->toIso8601String() : null,
                'className' => 'status-' . $event->status,
                'extendedProps' => [
                    'status' => $event->status,
                    'solar_system_id' => $event->solar_system_id,
                    'description' => $event->description ?? '',
                    'participants' => $event->participant_count ?? 0,
                    'tax_modifier' => $event->tax_modifier ?? 0,
                    'tax_modifier_label' => $event->getTaxModifierLabel(),
                    'total_mined' => $event->total_mined ?? 0
                ]
            ];
        });

        return view('mining-manager::events.calendar', compact('formattedEvents', 'upcomingEvents'));
    }
    
    /**
     * FIXED: Display user's personal events with proper character ID handling
     *
     * @return \Illuminate\View\View
     */
    public function myEvents()
    {
        $user = auth()->user();
        $characterIds = $this->getUserCharacterIds($user);
        
        if (empty($characterIds)) {
            \Log::warning('EventController: No character IDs found for user in myEvents', [
                'user_id' => $user->id
            ]);
            
            return view('mining-manager::events.my-events', [
                'activeEvents' => collect([]),
                'upcomingEvents' => collect([]),
                'completedEvents' => collect([]),
                'characterIds' => [],
                'eventSavingsMap' => [],
                'stats' => [
                    'total' => 0,
                    'active' => 0,
                    'total_mined' => 0,
                    'avg_per_event' => 0,
                    'total_event_savings' => 0,
                ]
            ]);
        }
        
        // The events the user's characters mined for, or the account signed
        // up for.
        $mine = function ($query) use ($characterIds, $user) {
            $query->whereHas('participants', function ($participants) use ($characterIds) {
                $participants->whereIn('character_id', $characterIds);
            });

            if (MiningEvent::hasRules()) {
                $query->orWhereHas('signups', function ($signups) use ($user) {
                    $signups->where('user_id', $user->id);
                });
            }
        };

        $activeEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'active')
            ->where($mine)
            ->get();

        $upcomingEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'planned')
            ->where($mine)
            ->orderBy('start_time', 'asc')
            ->get();

        $completedEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'completed')
            ->where($mine)
            ->orderBy('end_time', 'desc')
            ->limit(20)
            ->get();
        
        // Calculate statistics. The view labels these as "ISK" (my-events
        // info-boxes), so they MUST aggregate value_mined (ISK) — not
        // quantity_mined (units). Previously this summed quantity_mined,
        // producing wildly wrong "ISK" numbers that were actually unit
        // counts.
        $allParticipations = EventParticipant::whereIn('character_id', $characterIds)->get();

        // Per-event savings map — {event_id => total tax saved}. Walks
        // ore_types JSON on the user's daily summaries and attributes
        // event_discount_amount by event_id. Used for per-event card
        // sub-lines and for the grand-total stat box.
        $eventSavingsMap = app(\MiningManager\Services\Ledger\LedgerSummaryService::class)
            ->getEventSavingsByEvent($characterIds);
        $totalEventSavings = array_sum($eventSavingsMap);

        $stats = [
            'total' => $allParticipations->count(),
            'active' => $activeEvents->count(),
            'total_mined' => $allParticipations->sum('value_mined'),
            'avg_per_event' => $allParticipations->count() > 0
                ? $allParticipations->avg('value_mined')
                : 0,
            'total_event_savings' => $totalEventSavings,
        ];

        return view('mining-manager::events.my-events', compact(
            'activeEvents',
            'upcomingEvents',
            'completedEvents',
            'stats',
            'characterIds',
            'eventSavingsMap'
        ));
    }

    /**
     * Update event data (participants, statistics)
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateData($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);
            $this->eventService->updateEventData($event);

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event data updated successfully');
        } catch (\Exception $e) {
            \Log::error('EventController: Error updating event data', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()
                ->with('error', 'Error updating event data: ' . $e->getMessage());
        }
    }

    /**
     * Sign the user's account up for an event, every character on it. On an
     * event for those who sign up, the update that runs every minute gives
     * their mining the rate from here on.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function join($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);

            // Sign-ups stay open until the event ends.
            if (!$event->takesSignUps()) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.cannot_join_event')
                ], 400);
            }

            if (!MiningEvent::hasRules()) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.signups_unavailable')
                ], 503);
            }

            $user = auth()->user();
            $characterId = $this->getMainCharacterId($user);

            if (!$characterId) {
                \Log::warning('EventController: No character ID found when trying to join event', [
                    'user_id' => $user->id,
                    'event_id' => $id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No character found for your account'
                ], 400);
            }

            $signup = EventSignup::firstOrCreate(
                ['event_id' => $event->id, 'user_id' => $user->id],
                ['character_id' => $characterId, 'signed_up_at' => Carbon::now()]
            );

            if (!$signup->wasRecentlyCreated) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.already_joined')
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => trans($event->needsSignUp() ? 'mining-manager::events.joined_success_rate' : 'mining-manager::events.joined_success')
            ]);
        } catch (\Exception $e) {
            \Log::error('EventController: Error joining event', [
                'event_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::events.join_failed')
            ], 500);
        }
    }

    /**
     * Withdraw the user's account from an event. Like signing up, this can
     * only change until the event ends; a day already billed keeps what it
     * was billed.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function leave($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);

            if (!$event->takesSignUps()) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.cannot_join_event')
                ], 400);
            }

            $withdrawn = MiningEvent::hasRules()
                ? EventSignup::where('event_id', $event->id)->where('user_id', auth()->user()->id)->delete()
                : 0;

            if ($withdrawn === 0) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.not_participating')
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::events.left_success')
            ]);
        } catch (\Exception $e) {
            \Log::error('EventController: Error leaving event', [
                'event_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::events.leave_failed')
            ], 500);
        }
    }

    // ==================== HELPER METHODS (SeAT v5.x COMPATIBLE) ====================

    /**
     * Build a tax-scope compatibility map for the event form.
     *
     * Produces a data structure the create/edit views render as:
     *   - a "current tax settings" badge row
     *   - a per-event-type compatibility hint (full / partial / empty)
     *   - a "suggested event types" list based on what's taxed
     *
     * The map is serialised to JSON and read by event-tax-compat.js so
     * the warning panel updates immediately when the user changes the
     * event type dropdown — no submit-then-see-error loop.
     *
     * @return array{
     *   category_taxed: array<string, bool>,
     *   moon_mode: string,
     *   event_type_compat: array<string, array{
     *       taxed: int, untaxed: int, total: int, status: string,
     *       taxed_categories: array<string>, untaxed_categories: array<string>
     *   }>
     * }
     */
    private function buildTaxCompatibilityMap(): array
    {
        // Which corporation the event is for is picked on the form, so the
        // warning covers what any tax profile taxes.
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $taxSelector = $settingsService->usesTaxProfiles()
            ? $settingsService->taxSelectorAcross($settingsService->taxProfiles())
            : $settingsService->getTaxSelector();

        $categoryTaxed = [
            'ore' => !empty($taxSelector['ore']),
            'ice' => !empty($taxSelector['ice']),
            'gas' => !empty($taxSelector['gas']),
            'abyssal' => !empty($taxSelector['abyssal_ore']),
            'triglavian' => !empty($taxSelector['triglavian_ore']),
        ];

        // Moon is tri-state: 'none' | 'corp_only' | 'all'
        $moonMode = 'none';
        if (empty($taxSelector['no_moon_ore'])) {
            if (!empty($taxSelector['only_corp_moon_ore'])) {
                $moonMode = 'corp_only';
            } elseif (!empty($taxSelector['all_moon_ore'])) {
                $moonMode = 'all';
            }
        }

        $eventTypeCompat = [];
        foreach (MiningEvent::EVENT_TYPE_ORE_CATEGORIES as $eventType => $categories) {
            $taxedList = [];
            $untaxedList = [];

            foreach ($categories as $category) {
                $isTaxed = str_starts_with($category, 'moon_')
                    ? $moonMode !== 'none'
                    : ($categoryTaxed[$category] ?? false);

                $isTaxed ? $taxedList[] = $category : $untaxedList[] = $category;
            }

            $total = count($categories);
            $taxedCount = count($taxedList);
            $untaxedCount = count($untaxedList);
            $status = $taxedCount === 0
                ? 'empty'
                : ($untaxedCount === 0 ? 'full' : 'partial');

            $eventTypeCompat[$eventType] = [
                'taxed' => $taxedCount,
                'untaxed' => $untaxedCount,
                'total' => $total,
                'status' => $status,
                'taxed_categories' => $taxedList,
                'untaxed_categories' => $untaxedList,
            ];
        }

        return [
            'category_taxed' => $categoryTaxed,
            'moon_mode' => $moonMode,
            'event_type_compat' => $eventTypeCompat,
        ];
    }

    /**
     * FIXED: Get user's character IDs with multiple fallback methods
     * Same defensive approach as DashboardController
     */
    private function getUserCharacterIds($user)
    {
        if (!$user) {
            \Log::warning('EventController: No user provided to getUserCharacterIds');
            return [];
        }
        
        // Method 1: Try the characters relationship (preferred)
        try {
            if (method_exists($user, 'characters')) {
                $characters = $user->characters;
                if ($characters && $characters->count() > 0) {
                    $ids = $characters->pluck('character_id')->toArray();
                    \Log::debug('EventController: Found character IDs via relationship', [
                        'user_id' => $user->id,
                        'count' => count($ids)
                    ]);
                    return $ids;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('EventController: Failed to load characters via relationship', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try to load the relationship explicitly
        try {
            $user->load('characters');
            if ($user->relationLoaded('characters') && $user->characters->count() > 0) {
                $ids = $user->characters->pluck('character_id')->toArray();
                \Log::debug('EventController: Found character IDs via explicit load', [
                    'user_id' => $user->id,
                    'count' => count($ids)
                ]);
                return $ids;
            }
        } catch (\Exception $e) {
            \Log::warning('EventController: Failed to explicitly load characters', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 3: Direct database query. SeAT 5 links a character to its
        // user in refresh_tokens; character_infos has no user_id.
        try {
            $characterIds = DB::table('refresh_tokens')
                ->where('user_id', $user->id)
                ->pluck('character_id')
                ->toArray();
            
            if (!empty($characterIds)) {
                \Log::debug('EventController: Found character IDs via direct query', [
                    'user_id' => $user->id,
                    'count' => count($characterIds)
                ]);
                return $characterIds;
            }
        } catch (\Exception $e) {
            \Log::error('EventController: Failed to get character IDs from database', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        \Log::warning('EventController: No character IDs found for user', [
            'user_id' => $user->id
        ]);
        
        return [];
    }

    /**
     * FIXED: Get main character ID for a user
     * Same defensive approach as DashboardController
     */
    private function getMainCharacterId($user)
    {
        if (!$user) {
            \Log::warning('EventController: No user provided to getMainCharacterId');
            return null;
        }
        
        // Method 1: Check if user has main_character_id property
        if (isset($user->main_character_id) && $user->main_character_id) {
            return $user->main_character_id;
        }
        
        // Method 2: Get the first character from the user's characters
        $characterIds = $this->getUserCharacterIds($user);
        
        if (!empty($characterIds)) {
            return $characterIds[0];
        }
        
        \Log::warning('EventController: No main character ID found for user', [
            'user_id' => $user->id
        ]);

        return null;
    }

    /**
     * Search for locations (systems, constellations, regions) for event creation.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchLocations(Request $request)
    {
        $query = $request->input('q', '');
        $scope = $request->input('scope', 'system');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        // Map scope to groupID in mapDenormalize
        // 3 = Region, 4 = Constellation, 5 = Solar System
        $groupIDs = match($scope) {
            'system' => [5],
            'constellation' => [4],
            'region' => [3],
            default => [5, 4, 3],
        };

        // Escape LIKE wildcards to prevent unintended pattern matching
        $escapedQuery = str_replace(['%', '_'], ['\%', '\_'], $query);

        $results = MapDenormalize::whereIn('groupID', $groupIDs)
            ->where('itemName', 'like', "%{$escapedQuery}%")
            ->select('itemID as id', 'itemName as text', 'groupID')
            ->orderBy('itemName')
            ->limit(25)
            ->get()
            ->map(function ($item) {
                $typeLabel = match($item->groupID) {
                    3 => 'Region',
                    4 => 'Constellation',
                    5 => 'System',
                    default => '',
                };
                return [
                    'id' => $item->id,
                    'text' => $item->text . ($typeLabel ? " ({$typeLabel})" : ''),
                ];
            });

        return response()->json($results);
    }
}
