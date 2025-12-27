<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\ReportGenerationService;
use MiningManager\Models\MiningReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    /**
     * Report generation service
     *
     * @var ReportGenerationService
     */
    protected $reportService;

    /**
     * Constructor
     *
     * @param ReportGenerationService $reportService
     */
    public function __construct(ReportGenerationService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Display all reports
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $type = $request->input('type', 'all');

        $query = MiningReport::query();

        if ($type !== 'all') {
            $query->where('report_type', $type);
        }

        $reports = $query->orderBy('generated_at', 'desc')->paginate(20);

        return view('mining-manager::reports.index', compact('reports', 'type'));
    }

    /**
     * Show the form for generating a new report
     *
     * @return \Illuminate\View\View
     */
    public function generate()
    {
        $reportTypes = [
            'daily' => 'Daily Report',
            'weekly' => 'Weekly Report',
            'monthly' => 'Monthly Report',
            'custom' => 'Custom Date Range',
        ];

        $formats = [
            'json' => 'JSON',
            'csv' => 'CSV',
            'pdf' => 'PDF',
        ];

        return view('mining-manager::reports.generate', compact('reportTypes', 'formats'));
    }

    /**
     * Store a newly generated report
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:daily,weekly,monthly,custom',
            'format' => 'required|in:json,csv,pdf',
            'start_date' => 'required_if:report_type,custom|nullable|date',
            'end_date' => 'required_if:report_type,custom|nullable|date|after:start_date',
        ]);

        try {
            $type = $request->input('report_type');
            $format = $request->input('format');

            // Determine date range
            if ($type === 'custom') {
                $startDate = Carbon::parse($request->input('start_date'));
                $endDate = Carbon::parse($request->input('end_date'));
            } else {
                [$startDate, $endDate] = $this->getDateRangeForType($type);
            }

            // Generate report
            $report = $this->reportService->generateReport($startDate, $endDate, $type, $format);

            return redirect()->route('mining-manager.reports.show', $report->id)
                ->with('success', 'Report generated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error generating report: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified report
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $report = MiningReport::findOrFail($id);

        // Decode report data
        $reportData = json_decode($report->data, true);

        return view('mining-manager::reports.show', compact('report', 'reportData'));
    }

    /**
     * Download the specified report
     *
     * @param int $id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     */
    public function download($id)
    {
        $report = MiningReport::findOrFail($id);

        // If file exists, download it
        if ($report->file_path && Storage::exists($report->file_path)) {
            return Storage::download($report->file_path, $this->generateFileName($report));
        }

        // Otherwise, generate on-the-fly based on format
        $reportData = json_decode($report->data, true);

        switch ($report->format) {
            case 'csv':
                return $this->downloadCsv($report, $reportData);
            case 'json':
                return $this->downloadJson($report, $reportData);
            case 'pdf':
                return $this->downloadPdf($report, $reportData);
            default:
                abort(400, 'Invalid report format');
        }
    }

    /**
     * Remove the specified report
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        try {
            $report = MiningReport::findOrFail($id);

            // Delete file if exists
            if ($report->file_path && Storage::exists($report->file_path)) {
                Storage::delete($report->file_path);
            }

            $report->delete();

            return redirect()->route('mining-manager.reports.index')
                ->with('success', 'Report deleted successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error deleting report: ' . $e->getMessage());
        }
    }

    /**
     * Cleanup old reports
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cleanup()
    {
        try {
            $retentionDays = config('mining-manager.reports.retention_days', 90);

            if ($retentionDays > 0) {
                $cutoffDate = Carbon::now()->subDays($retentionDays);
                
                $oldReports = MiningReport::where('generated_at', '<', $cutoffDate)->get();

                foreach ($oldReports as $report) {
                    if ($report->file_path && Storage::exists($report->file_path)) {
                        Storage::delete($report->file_path);
                    }
                    $report->delete();
                }

                return redirect()->route('mining-manager.reports.index')
                    ->with('success', "Cleaned up {$oldReports->count()} old reports");
            }

            return redirect()->route('mining-manager.reports.index')
                ->with('info', 'Report retention is disabled');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error cleaning up reports: ' . $e->getMessage());
        }
    }

    /**
     * Get date range for report type
     *
     * @param string $type
     * @return array
     */
    private function getDateRangeForType(string $type): array
    {
        $now = Carbon::now();

        switch ($type) {
            case 'daily':
                return [
                    $now->copy()->subDay()->startOfDay(),
                    $now->copy()->subDay()->endOfDay()
                ];
            case 'weekly':
                return [
                    $now->copy()->subWeek()->startOfWeek(),
                    $now->copy()->subWeek()->endOfWeek()
                ];
            case 'monthly':
                return [
                    $now->copy()->subMonth()->startOfMonth(),
                    $now->copy()->subMonth()->endOfMonth()
                ];
            default:
                return [$now->copy()->subMonth(), $now];
        }
    }

    /**
     * Generate filename for report
     *
     * @param MiningReport $report
     * @return string
     */
    private function generateFileName(MiningReport $report): string
    {
        return sprintf(
            'mining_report_%s_%s_%s.%s',
            $report->report_type,
            $report->start_date->format('Ymd'),
            $report->end_date->format('Ymd'),
            $report->format
        );
    }

    /**
     * Download report as CSV
     *
     * @param MiningReport $report
     * @param array $data
     * @return \Illuminate\Http\Response
     */
    private function downloadCsv(MiningReport $report, array $data)
    {
        $filename = $this->generateFileName($report);
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            
            // Write headers based on data structure
            if (!empty($data['miners'])) {
                fputcsv($file, ['Character', 'Quantity Mined', 'Value (ISK)', 'Tax Owed']);
                foreach ($data['miners'] as $miner) {
                    fputcsv($file, $miner);
                }
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download report as JSON
     *
     * @param MiningReport $report
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    private function downloadJson(MiningReport $report, array $data)
    {
        $filename = $this->generateFileName($report);

        return response()->json($data, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Download report as PDF
     *
     * @param MiningReport $report
     * @param array $data
     * @return \Illuminate\Http\Response
     */
    private function downloadPdf(MiningReport $report, array $data)
    {
        // PDF generation would require a PDF library like DomPDF or similar
        // For now, return a placeholder
        abort(501, 'PDF generation not yet implemented');
    }
}
