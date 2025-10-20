<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
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
     * Constructor
     *
     * @param EventManagementService $eventService
     */
    public function __construct(EventManagementService $eventService)
    {
        $this->eventService = $eventService;
    }

    /**
     * Display all mining events
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'all');

        $query = MiningEvent::with('creator');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $events = $query->orderBy('start_time', 'desc')->paginate(20);

        return view('mining-manager::events.index', compact('events', 'status'));
    }

    /**
     * Show the form for creating a new event
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('mining-manager::events.create');
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
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'solar_system_id' => 'nullable|integer',
            'bonus_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $event = MiningEvent::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'start_time' => Carbon::parse($request->input('start_time')),
                'end_time' => $request->input('end_time') ? Carbon::parse($request->input('end_time')) : null,
                'solar_system_id' => $request->input('solar_system_id'),
                'bonus_percentage' => $request->input('bonus_percentage', 0),
                'status' => 'planned',
                'created_by' => auth()->user()->id,
            ]);

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Mining event created successfully');
        } catch (\Exception $e) {
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
            'total_bonuses' => $participants->sum('bonus_earned'),
        ];

        return view('mining-manager::events.show', compact('event', 'participants', 'stats'));
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

        return view('mining-manager::events.edit', compact('event'));
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
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'solar_system_id' => 'nullable|integer',
            'bonus_percentage' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|in:planned,active,completed,cancelled',
        ]);

        try {
            $event = MiningEvent::findOrFail($id);

            $event->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'start_time' => Carbon::parse($request->input('start_time')),
                'end_time' => $request->input('end_time') ? Carbon::parse($request->input('end_time')) : null,
                'solar_system_id' => $request->input('solar_system_id'),
                'bonus_percentage' => $request->input('bonus_percentage', 0),
                'status' => $request->input('status'),
            ]);

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event updated successfully');
        } catch (\Exception $e) {
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

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event started successfully');
        } catch (\Exception $e) {
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
            
            $event->update([
                'status' => 'completed',
                'end_time' => Carbon::now(),
            ]);

            // Calculate final bonuses if enabled
            if ($event->bonus_percentage > 0) {
                $this->eventService->calculateBonuses($event);
            }

            return redirect()->route('mining-manager.events.show', $event->id)
                ->with('success', 'Event completed successfully');
        } catch (\Exception $e) {
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
        $events = MiningEvent::where('status', 'active')
            ->with(['participants.character', 'creator'])
            ->orderBy('start_time', 'desc')
            ->get();

        return view('mining-manager::events.active', compact('events'));
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
        
        return view('mining-manager::events.calendar', compact('events', 'upcomingEvents'));
    }
    
    /**
     * Display user's personal events
     *
     * @return \Illuminate\View\View
     */
    public function myEvents()
    {
        $userId = auth()->user()->id;
        
        // Get all events user is participating in
        $activeEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'active')
            ->whereHas('participants', function($query) use ($userId) {
                $query->where('character_id', $userId);
            })
            ->get();
        
        $upcomingEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'planned')
            ->whereHas('participants', function($query) use ($userId) {
                $query->where('character_id', $userId);
            })
            ->orderBy('start_time', 'asc')
            ->get();
        
        $completedEvents = MiningEvent::with('creator', 'participants')
            ->where('status', 'completed')
            ->whereHas('participants', function($query) use ($userId) {
                $query->where('character_id', $userId);
            })
            ->orderBy('end_time', 'desc')
            ->limit(20)
            ->get();
        
        // Calculate statistics
        $allParticipations = EventParticipant::where('character_id', $userId)->get();
        $stats = [
            'total' => $allParticipations->count(),
            'active' => $activeEvents->count(),
            'total_mined' => $allParticipations->sum('total_mined'),
            'avg_per_event' => $allParticipations->count() > 0 
                ? $allParticipations->avg('total_mined') 
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
            return redirect()->back()
                ->with('error', 'Error updating event data: ' . $e->getMessage());
        }
    }
}
