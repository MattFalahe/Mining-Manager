<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningReport;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ReportGenerationService
{
    /**
     * Mining analytics service
     *
     * @var MiningAnalyticsService
     */
    protected $analyticsService;

    /**
     * Constructor
     *
     * @param MiningAnalyticsService $analyticsService
     */
    public function __construct(MiningAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Generate a comprehensive mining report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $type
     * @param string $format
     * @return MiningReport
     */
    public function generateReport(Carbon $startDate, Carbon $endDate, string $type = 'custom', string $format = 'json'): MiningReport
    {
        // Collect report data
        $reportData = $this->collectReportData($startDate, $endDate);

        // Create report record
        $report = MiningReport::create([
            'report_type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'format' => $format,
            'data' => json_encode($reportData),
            'generated_at' => Carbon::now(),
            'generated_by' => auth()->check() ? auth()->user()->id : null,
        ]);

        // Generate file if not JSON
        if ($format !== 'json') {
            $filePath = $this->generateReportFile($report, $reportData, $format);
            $report->update(['file_path' => $filePath]);
        }

        // Send webhook notification for report generation
        try {
            app(\MiningManager\Services\Notification\WebhookService::class)->sendReportNotification($report, $reportData);
        } catch (\Exception $e) {
            Log::warning("Failed to send report webhook: " . $e->getMessage());
        }

        return $report;
    }

    /**
     * Collect all report data.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function collectReportData(Carbon $startDate, Carbon $endDate): array
    {
        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1,
            ],
            'summary' => $this->getSummary($startDate, $endDate),
            'miners' => $this->getMinerData($startDate, $endDate),
            'ore_types' => $this->getOreTypeData($startDate, $endDate),
            'systems' => $this->getSystemData($startDate, $endDate),
            'daily_breakdown' => $this->getDailyBreakdown($startDate, $endDate),
            'taxes' => $this->getTaxData($startDate, $endDate),
        ];
    }

    /**
     * Get summary statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getSummary(Carbon $startDate, Carbon $endDate): array
    {
        $totalQuantity = $this->analyticsService->getTotalVolume($startDate, $endDate);
        $totalValue = $this->analyticsService->getTotalValue($startDate, $endDate);
        $uniqueMiners = $this->analyticsService->getUniqueMinerCount($startDate, $endDate);

        return [
            'total_quantity' => $totalQuantity,
            'total_value' => $totalValue,
            'unique_miners' => $uniqueMiners,
            'average_per_miner' => $uniqueMiners > 0 ? $totalQuantity / $uniqueMiners : 0,
            'average_value_per_miner' => $uniqueMiners > 0 ? $totalValue / $uniqueMiners : 0,
        ];
    }

    /**
     * Get miner data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getMinerData(Carbon $startDate, Carbon $endDate): array
    {
        $topMinersCount = config('mining-manager.reports.top_miners_count', 10);
        $topMiners = $this->analyticsService->getTopMiners($startDate, $endDate, $topMinersCount);

        return [
            'top_miners' => $topMiners->map(function ($miner) {
                return [
                    'name' => $miner->name,
                    'quantity' => $miner->total_quantity,
                    'value' => $miner->total_value ?? 0,
                ];
            })->toArray(),
            'total_count' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
        ];
    }

    /**
     * Get ore type data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getOreTypeData(Carbon $startDate, Carbon $endDate): array
    {
        $oreBreakdown = $this->analyticsService->getOreBreakdown($startDate, $endDate);

        return $oreBreakdown->map(function ($ore) {
            return [
                'name' => $ore->ore_name,
                'quantity' => $ore->total_quantity,
                'value' => $ore->total_value ?? 0,
            ];
        })->toArray();
    }

    /**
     * Get system data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getSystemData(Carbon $startDate, Carbon $endDate): array
    {
        $systemBreakdown = $this->analyticsService->getSystemBreakdown($startDate, $endDate);

        return $systemBreakdown->map(function ($system) {
            return [
                'name' => $system->system_name,
                'quantity' => $system->total_quantity,
                'unique_miners' => $system->unique_miners,
            ];
        })->toArray();
    }

    /**
     * Get daily breakdown for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getDailyBreakdown(Carbon $startDate, Carbon $endDate): array
    {
        $dailyTrends = $this->analyticsService->getDailyTrends($startDate, $endDate);

        return $dailyTrends->map(function ($day) {
            return [
                'date' => $day->date,
                'quantity' => $day->total_quantity,
                'value' => $day->total_value ?? 0,
                'miners' => $day->unique_miners,
            ];
        })->toArray();
    }

    /**
     * Get tax data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getTaxData(Carbon $startDate, Carbon $endDate): array
    {
        $taxes = MiningTax::whereBetween('month', [$startDate->startOfMonth(), $endDate->endOfMonth()])
            ->get();

        return [
            'total_owed' => $taxes->sum('amount_owed'),
            'total_paid' => $taxes->sum('amount_paid'),
            'unpaid' => $taxes->where('status', 'unpaid')->sum('amount_owed'),
            'overdue' => $taxes->where('status', 'overdue')->sum('amount_owed'),
            'collection_rate' => $taxes->sum('amount_owed') > 0 
                ? ($taxes->sum('amount_paid') / $taxes->sum('amount_owed')) * 100 
                : 0,
        ];
    }

    /**
     * Generate report file in specified format.
     *
     * @param MiningReport $report
     * @param array $data
     * @param string $format
     * @return string
     */
    private function generateReportFile(MiningReport $report, array $data, string $format): string
    {
        $filename = sprintf(
            'mining_report_%s_%s_%s.%s',
            $report->report_type,
            $report->start_date->format('Ymd'),
            $report->id,
            $format
        );

        $path = "reports/{$filename}";

        switch ($format) {
            case 'csv':
                $this->generateCsvFile($path, $data);
                break;
            case 'pdf':
                $this->generatePdfFile($path, $data);
                break;
        }

        return $path;
    }

    /**
     * Generate CSV file.
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    private function generateCsvFile(string $path, array $data): void
    {
        $csv = fopen('php://temp', 'r+');

        // Write miners data
        fputcsv($csv, ['Top Miners']);
        fputcsv($csv, ['Name', 'Quantity', 'Value (ISK)']);
        foreach ($data['miners']['top_miners'] as $miner) {
            fputcsv($csv, [
                $miner['name'],
                $miner['quantity'],
                number_format($miner['value'], 2),
            ]);
        }

        fputcsv($csv, []); // Empty line

        // Write ore types data
        fputcsv($csv, ['Ore Types']);
        fputcsv($csv, ['Ore Name', 'Quantity', 'Value (ISK)']);
        foreach ($data['ore_types'] as $ore) {
            fputcsv($csv, [
                $ore['name'],
                $ore['quantity'],
                number_format($ore['value'], 2),
            ]);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        Storage::put($path, $content);
    }

    /**
     * Generate PDF file.
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    private function generatePdfFile(string $path, array $data): void
    {
        $pdf = Pdf::loadView('mining-manager::reports.pdf.report', ['data' => $data]);
        Storage::put($path, $pdf->output());
    }

    /**
     * Export report to CSV.
     *
     * @param MiningReport $report
     * @param string $path
     * @return void
     */
    public function exportToCsv(MiningReport $report, string $path): void
    {
        $data = json_decode($report->data, true);
        $this->generateCsvFile($path, $data);
    }

    /**
     * Export report to PDF.
     *
     * @param MiningReport $report
     * @param string $path
     * @return void
     */
    public function exportToPdf(MiningReport $report, string $path): void
    {
        $data = json_decode($report->data, true);
        $this->generatePdfFile($path, $data);
    }

    /**
     * Generate a quick export file.
     *
     * @param string $exportType
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $format csv|json
     * @return array{id: int, url: string}
     */
    public function generateExport(string $exportType, Carbon $startDate, Carbon $endDate, string $format): array
    {
        // Collect data based on export type
        $rows = match ($exportType) {
            'mining_activity' => $this->getMiningActivityExport($startDate, $endDate),
            'tax_records'     => $this->getTaxRecordsExport($startDate, $endDate),
            'miner_stats'     => $this->getMinerData($startDate, $endDate),
            'system_stats'    => $this->getSystemData($startDate, $endDate),
            'ore_breakdown'   => $this->getOreTypeData($startDate, $endDate),
            'event_data'      => $this->getEventDataExport($startDate, $endDate),
            default           => throw new \InvalidArgumentException("Unknown export type: {$exportType}"),
        };

        // Create a MiningReport record for tracking
        $report = MiningReport::create([
            'report_type'  => $exportType,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'format'       => $format,
            'data'         => json_encode($rows),
            'generated_at' => Carbon::now(),
            'generated_by' => auth()->check() ? auth()->user()->id : null,
        ]);

        // Build file path
        $filename = sprintf(
            'export_%s_%s_%s_%d.%s',
            $exportType,
            $startDate->format('Ymd'),
            $endDate->format('Ymd'),
            $report->id,
            $format
        );
        $path = "exports/{$filename}";

        // Generate the file
        if ($format === 'csv') {
            $this->generateExportCsv($path, $rows, $exportType);
        } else {
            Storage::put($path, json_encode($rows, JSON_PRETTY_PRINT));
        }

        $report->update(['file_path' => $path]);

        return [
            'id'  => $report->id,
            'url' => route('mining-manager.reports.export.download', $report->id),
        ];
    }

    /**
     * Get raw mining activity data for export.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getMiningActivityExport(Carbon $startDate, Carbon $endDate): array
    {
        return MiningLedger::with(['character', 'type', 'solarSystem'])
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($entry) {
                return [
                    'date'         => $entry->date->toDateString(),
                    'character'    => $entry->character->name ?? 'Unknown',
                    'character_id' => $entry->character_id,
                    'ore_type'     => $entry->type_name,
                    'type_id'      => $entry->type_id,
                    'quantity'     => $entry->quantity,
                    'system'       => $entry->system_name,
                    'system_id'    => $entry->solar_system_id,
                    'total_value'  => (float) $entry->total_value,
                    'tax_amount'   => (float) $entry->tax_amount,
                ];
            })
            ->toArray();
    }

    /**
     * Get tax records for export.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getTaxRecordsExport(Carbon $startDate, Carbon $endDate): array
    {
        return MiningTax::with('character')
            ->whereBetween('month', [$startDate->startOfMonth(), $endDate->endOfMonth()])
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($tax) {
                return [
                    'month'        => $tax->month->format('Y-m'),
                    'character'    => $tax->character->name ?? 'Unknown',
                    'character_id' => $tax->character_id,
                    'amount_owed'  => (float) $tax->amount_owed,
                    'amount_paid'  => (float) $tax->amount_paid,
                    'balance'      => (float) $tax->getRemainingBalance(),
                    'status'       => $tax->status,
                    'due_date'     => $tax->due_date ? $tax->due_date->toDateString() : null,
                    'paid_at'      => $tax->paid_at ? $tax->paid_at->toDateTimeString() : null,
                ];
            })
            ->toArray();
    }

    /**
     * Get event data for export.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getEventDataExport(Carbon $startDate, Carbon $endDate): array
    {
        return MiningEvent::with('participants')
            ->whereBetween('start_time', [$startDate, $endDate])
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function ($event) {
                return [
                    'name'              => $event->name,
                    'type'              => $event->getTypeLabel(),
                    'status'            => $event->status,
                    'start_time'        => $event->start_time->toDateTimeString(),
                    'end_time'          => $event->end_time ? $event->end_time->toDateTimeString() : null,
                    'duration_hours'    => $event->getDuration(),
                    'participant_count' => $event->participant_count,
                    'total_mined'       => $event->total_mined,
                    'tax_modifier'      => $event->getFormattedTaxModifier(),
                    'avg_per_participant' => $event->getAveragePerParticipant(),
                ];
            })
            ->toArray();
    }

    /**
     * Generate a CSV file from export data.
     *
     * @param string $path
     * @param array $rows
     * @param string $exportType
     * @return void
     */
    private function generateExportCsv(string $path, array $rows, string $exportType): void
    {
        $csv = fopen('php://temp', 'r+');

        if (empty($rows)) {
            fputcsv($csv, ['No data found for the selected period']);
            rewind($csv);
            Storage::put($path, stream_get_contents($csv));
            fclose($csv);
            return;
        }

        // Write header row from the first record's keys
        fputcsv($csv, array_keys($rows[0]));

        // Write data rows
        foreach ($rows as $row) {
            fputcsv($csv, array_values($row));
        }

        rewind($csv);
        Storage::put($path, stream_get_contents($csv));
        fclose($csv);
    }
}
