<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_manager_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'corporation_id',
        'description',
    ];

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed $default
     * @param int|null $corporationId
     * @return mixed
     */
    public static function getValue(string $key, $default = null, ?int $corporationId = null)
    {
        // First, try to find corporation-specific setting
        if ($corporationId) {
            $setting = self::where('key', $key)
                ->where('corporation_id', $corporationId)
                ->first();

            if ($setting) {
                return self::castValue($setting->value, $setting->type);
            }
        }

        // Fallback to global setting (corporation_id IS NULL)
        $setting = self::where('key', $key)
            ->whereNull('corporation_id')
            ->first();

        if ($setting) {
            return self::castValue($setting->value, $setting->type);
        }

        // If no corporation was specified but we still didn't find a global setting,
        // try to find ANY setting with this key (for backwards compatibility)
        if (!$corporationId) {
            $setting = self::where('key', $key)->first();

            if ($setting) {
                return self::castValue($setting->value, $setting->type);
            }
        }

        return $default;
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @param int|null $corporationId
     * @param string|null $description
     * @return Setting
     */
    public static function setValue(string $key, $value, string $type = 'string', ?int $corporationId = null, ?string $description = null)
    {
        return self::updateOrCreate(
            [
                'key' => $key,
                'corporation_id' => $corporationId,
            ],
            [
                'value' => self::prepareValue($value, $type),
                'type' => $type,
                'description' => $description,
            ]
        );
    }

    /**
     * Cast value to appropriate type.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private static function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float', 'decimal' => (float) $value,
            'array', 'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Prepare value for storage.
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    private static function prepareValue($value, string $type): string
    {
        if (in_array($type, ['array', 'json'])) {
            return json_encode($value);
        }

        if ($type === 'boolean') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
