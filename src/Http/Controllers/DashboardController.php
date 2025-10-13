<?php

namespace MattFalahe\Seat\MiningManager\Http\Controllers;

use Seat\Web\Http\Controllers\Controller;
use MattFalahe\Seat\MiningManager\Services\MiningAnalytics;
use MattFalahe\Seat\MiningManager\Services\DashboardMetrics;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $analytics;
    protected $metrics;
    
    public function __construct(MiningAnalytics $analytics, DashboardMetrics $metrics)
    {
        $this->analytics = $analytics;
        $this->metrics = $metrics;
    }
    
    public function index()
    {
        $corporation = $this->getCorporation();
        
        $data = [
            'summary' => $this->metrics->getSummaryCards($corporation->corporation_id),
            'topMiners' => $this->analytics->getTopMiners($corporation->corporation_id, 10),
            'recentActivity' => $this->analytics->getRecentActivity($corporation->corporation_id),
            'upcomingExtractions' => $this->analytics->getUpcomingExtractions($corporation->corporation_id),
        ];
        
        return view('mining-manager::dashboard.index', $data);
    }
    
    public function getData(Request $request)
    {
        $corporation = $this->getCorporation();
        $period = $request->get('period', 30);
        $type = $request->get('type', 'all');
        
        $data = [
            'trends' => $this->analytics->getTrends($corporation->corporation_id, $period),
            'distribution' => $this->analytics->getOreDistribution($corporation->corporation_id, $period),
            'locations' => $this->analytics->getLocationBreakdown($corporation->corporation_id, $period),
            'timeline' => $this->analytics->getMiningTimeline($corporation->corporation_id, $period, $type),
        ];
        
        return response()->json($data);
    }
    
    private function getCorporation()
    {
        return auth()->user()->main_character->corporation;
    }
}
