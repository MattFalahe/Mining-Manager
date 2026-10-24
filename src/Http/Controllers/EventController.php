<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventMoon;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\EventSignup;
use MiningManager\Services\Events\EventMiningAggregator;
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
     * Constructor
     *
     * @param EventManagementService $eventService
     * @param NotificationService $notificationService
     */
    public function __construct(
        EventManagementService $eventService,
        NotificationService $notificationService
    ) {
        $this->eventService = $eventService;
        $this->notificationService = $notificationService;
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

        return view('mining-manager::events.index', compact('events', 'status', 'corporationId', 'corporations', 'stats'));
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

        return view('mining-manager::events.create', compact('corporations', 'taxCompat', 'refineries', 'prefill'));
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
            'status' => $event ? 'required|in:planned,active,completed,cancelled' : 'nullable',
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

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        if ($moon) {
            $start = Carbon::createFromFormat('Y-m-d', $request->input('start_day'))->startOfDay();
            $end = $request->filled('end_day') ? Carbon::createFromFormat('Y-m-d', $request->input('end_day'))->endOfDay() : null;
        } else {
            $start = Carbon::parse($request->input('start_time'));
            $end = $request->filled('end_time') ? Carbon::parse($request->input('end_time')) : null;
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

        if ($event) {
            $attributes['status'] = $request->input('status');
        }

        return [$attributes, $refineries];
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

        try {
            $event = DB::transaction(function () use ($attributes, $refineries) {
                $event = MiningEvent::create($attributes + [
                    'status' => 'planned',
                    'created_by' => auth()->user()->id,
                ]);
                $this->syncRefineries($event, $refineries);

                return $event;
            });

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
            $event = MiningEvent::with(['participants', 'creator'])->findOrFail($id);

            // Get participants sorted by quantity mined
            $participants = $event->participants()
                ->orderBy('quantity_mined', 'desc')
                ->get();

            // Calculate statistics
            $stats = [
                'total_mined' => $participants->sum('quantity_mined'),
                'participant_count' => $participants->count(),
                'average_per_miner' => $participants->count() > 0
                    ? $participants->sum('quantity_mined') / $participants->count()
                    : 0,
                'tax_modifier' => $event->tax_modifier,
                'tax_modifier_label' => $event->getTaxModifierLabel(),
            ];

            return view('mining-manager::events.show', compact('event', 'participants', 'stats'));
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

        // Same scoped dropdown as create() — corps with MM settings +
        // moon owner. Plus, defensively, the event's currently-assigned
        // corp_id (if any) so an event tied to a corp that's no longer
        // in the dropdown set still renders correctly.
        $corporations = $this->getEventDropdownCorporations((int) ($event->corporation_id ?? 0));

        $taxCompat = $this->buildTaxCompatibilityMap();
        $refineries = app(EventRefineries::class)->choices($event->refineryIds());

        return view('mining-manager::events.edit', compact('event', 'corporations', 'taxCompat', 'refineries'));
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
        [$attributes, $refineries] = $this->eventFromForm($request, $event);

        try {
            DB::transaction(function () use ($event, $attributes, $refineries) {
                // Refineries first, so a scope edit gathers the event with
                // them. Refineries alone changing is not a change to the
                // event row, so it gathers the event itself.
                $refineriesChanged = $this->syncRefineries($event, $refineries);
                $event->update($attributes);

                if ($refineriesChanged && !$event->wasChanged(MiningEvent::SCOPE_AFFECTING_FIELDS)) {
                    app(EventMiningAggregator::class)->aggregate($event, true);
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
            $event->delete();

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
