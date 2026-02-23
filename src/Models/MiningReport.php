<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

class MiningReport extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_reports';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'report_type',
        'start_date',
        'end_date',
        'format',
        'data',
        'file_path',
        'generated_at',
        'generated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'generated_at' => 'datetime',
        'data' => 'array',
    ];

    /**
     * Scope a query to filter by report type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('report_type', $type);
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_date', [$startDate, $endDate]);
    }

    /**
     * Get decoded report data.
     *
     * @return array
     */
    public function getDecodedData()
    {
        return json_decode($this->data, true) ?? [];
    }

    /**
     * Check if report file exists.
     *
     * @return bool
     */
    public function fileExists()
    {
        return $this->file_path && \Storage::exists($this->file_path);
    }

    /**
     * Get file size in bytes.
     *
     * @return int|null
     */
    public function getFileSize()
    {
        if (!$this->fileExists()) {
            return null;
        }

        return \Storage::size($this->file_path);
    }

    /**
     * Get human-readable file size.
     *
     * @return string|null
     */
    public function getHumanFileSize()
    {
        $size = $this->getFileSize();

        if ($size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}
