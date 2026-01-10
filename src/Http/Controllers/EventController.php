<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Services\Notification\WebhookService;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
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
     * Webhook service
     *
     * @var WebhookService
     */
    protected $webhookService;

    /**
     * Constructor
     *
     * @param EventManagementService $eventService
     * @param NotificationService $notificationService
     * @param WebhookService $webhookService
     */
    public function __construct(
        EventManagementService $eventService,
        NotificationService $notificationService,
        WebhookService $webhookService
    ) {
        $this->eventService = $eventService;
        $this->notificationService = $notificationService;
        $this->webhookService = $webhookService;
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

        // Calculate event statistics for summary boxes
        $stats = [
            'active' => MiningEvent::where('status', 'active')->count(),
            'upcoming' => MiningEvent::where('status', 'planned')
                ->where('start_time', '<=', now()->addDays(7))
                ->where('start_time', '>', now())
                ->count(),
            'participants' => DB::table('event_participants')->distinct('character_id')->count('character_id'),
            'total_value' => MiningEvent::whereMonth('start_time', now()->month)
                ->whereYear('start_time', now()->year)
                ->sum('total_mined'),
        ];

        return view('mining-manager::events.index', compact('events', 'status', 'corporationId', 'corporations', 'stats'));
    }

    /**
     * Show the form for creating a new event
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags();
        if (!($features['enable_events'] ?? true) || !($features['allow_event_creation'] ?? true)) {
            return redirect()->route('mining-manager.dashboard.index')
                ->with('warning', 'Event creation is currently disabled. Enable it in Settings > Features.');
        }

        // Get configured corporations for the dropdown
        $corporations = DB::table('corporation_infos')
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::events.create', compact('corporations'));
    }

    /**
     * Store a newly created event
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string|in:mining_op,moon_extraction,ice_mining,gas_huffing,special',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'location_scope' => 'required|string|in:any,system,constellation,region',
            'solar_system_id' => 'nullable|integer',
            'tax_modifier' => 'required|integer|min:-100|max:100',
            'corporation_id' => 'nullable|integer',
        ]);

        try {
            $event = MiningEvent::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'type' => $request->input('type', 'mining_op'),
                'start_time' => Carbon::parse($request->input('start_time')),
                'end_time' => $request->input('end_time') ? Carbon::parse($request->input('end_time')) : null,
                'location_scope' => $request->input('location_scope', 'any'),
                'solar_system_id' => $request->input('location_scope') !== 'any' ? $request->input('solar_system_id') : null,
                'tax_modifier' => $request->input('tax_modifier', 0),
                'corporation_id' => $request->input('corporation_id'),
                'status' => 'planned',
                'created_by' => auth()->user()->id,
            ]);

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

                // Also send to configured webhooks
                try {
                    $this->webhookService->sendEventNotification('event_created', $event);
                } catch (\Exception $e) {
                    \Log::warning('EventController: Failed to send event created webhook', [
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
            $event = MiningEvent::with(['participants.character', 'creator'])->findOrFail($id);

            // Get participants sorted by quantity mined
            $participants = $event->participants()
                ->with('character')
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

        // Get configured corporations for the dropdown
        $corporations = DB::table('corporation_infos')
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::events.edit', compact('event', 'corporations'));
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
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string|in:mining_op,moon_extraction,ice_mining,gas_huffing,special',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'location_scope' => 'required|string|in:any,system,constellation,region',
            'solar_system_id' => 'nullable|integer',
            'tax_modifier' => 'required|integer|min:-100|max:100',
            'corporation_id' => 'nullable|integer',
            'status' => 'required|in:planned,active,completed,cancelled',
        ]);

        try {
            $event = MiningEvent::findOrFail($id);

            $event->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'type' => $request->input('type', 'mining_op'),
                'start_time' => Carbon::parse($request->input('start_time')),
                'end_time' => $request->input('end_time') ? Carbon::parse($request->input('end_time')) : null,
                'location_scope' => $request->input('location_scope', 'any'),
                'solar_system_id' => $request->input('location_scope') !== 'any' ? $request->input('solar_system_id') : null,
                'tax_modifier' => $request->input('tax_modifier', 0),
                'corporation_id' => $request->input('corporation_id'),
                'status' => $request->input('status'),
            ]);

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

            $event->update([
                'status' => 'active',
                'start_time' => Carbon::now(),
            ]);

            // Send event started notification
            try {
                $this->notificationService->sendEventStarted($event);
            } catch (\Exception $e) {
                \Log::warning('EventController: Failed to send event started notification', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Also send to configured webhooks
            try {
                $this->webhookService->sendEventNotification('event_started', $event);
            } catch (\Exception $e) {
                \Log::warning('EventController: Failed to send event started webhook', [
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

            $event->update([
                'status' => 'completed',
                'end_time' => Carbon::now(),
            ]);

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

            // Also send to configured webhooks
            try {
                $this->webhookService->sendEventNotification('event_completed', $event);
            } catch (\Exception $e) {
                \Log::warning('EventController: Failed to send event completed webhook', [
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
                'stats' => [
                    'total' => 0,
                    'active' => 0,
                    'total_mined' => 0,
                    'avg_per_event' => 0,
                ]
            ]);
        }
        
        // Get all events user's characters are participating in
        $activeEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'active')
            ->whereHas('participants', function($query) use ($characterIds) {
                $query->whereIn('character_id', $characterIds);
            })
            ->get();
        
        $upcomingEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'planned')
            ->whereHas('participants', function($query) use ($characterIds) {
                $query->whereIn('character_id', $characterIds);
            })
            ->orderBy('start_time', 'asc')
            ->get();
        
        $completedEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'completed')
            ->whereHas('participants', function($query) use ($characterIds) {
                $query->whereIn('character_id', $characterIds);
            })
            ->orderBy('end_time', 'desc')
            ->limit(20)
            ->get();
        
        // Calculate statistics
        $allParticipations = EventParticipant::whereIn('character_id', $characterIds)->get();
        $stats = [
            'total' => $allParticipations->count(),
            'active' => $activeEvents->count(),
            'total_mined' => $allParticipations->sum('quantity_mined'),
            'avg_per_event' => $allParticipations->count() > 0
                ? $allParticipations->avg('quantity_mined')
                : 0,
        ];
        
        return view('mining-manager::events.my-events', compact(
            'activeEvents', 
            'upcomingEvents', 
            'completedEvents', 
            'stats'
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
     * FIXED: Join a mining event with proper character ID handling
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function join($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);
            
            // Check if event is joinable (upcoming or active)
            if (!in_array($event->status, ['planned', 'active'])) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.cannot_join_event')
                ], 400);
            }

            // FIXED: Get current user's main character ID using proper method
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

            // Check if already participating
            $existing = EventParticipant::where('event_id', $event->id)
                ->where('character_id', $characterId)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.already_joined')
                ], 400);
            }

            // Add participant
            EventParticipant::create([
                'event_id' => $event->id,
                'character_id' => $characterId,
                'joined_at' => Carbon::now(),
            ]);

            // Update participant count
            $event->increment('participant_count');

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::events.joined_success')
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
     * FIXED: Leave a mining event with proper character ID handling
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function leave($id)
    {
        try {
            $event = MiningEvent::findOrFail($id);
            
            // FIXED: Get current user's main character ID using proper method
            $user = auth()->user();
            $characterId = $this->getMainCharacterId($user);
            
            if (!$characterId) {
                \Log::warning('EventController: No character ID found when trying to leave event', [
                    'user_id' => $user->id,
                    'event_id' => $id
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'No character found for your account'
                ], 400);
            }

            // Find participation record
            $participant = EventParticipant::where('event_id', $event->id)
                ->where('character_id', $characterId)
                ->first();

            if (!$participant) {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::events.not_participating')
                ], 400);
            }

            // Remove participant
            $participant->delete();

            // Update participant count
            $event->decrement('participant_count');

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
        
        // Method 3: Direct database query (most reliable fallback)
        try {
            $characterIds = DB::table('character_infos')
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
