<?php

namespace MiningManager\Services\Configuration;

/**
 * Every setting the plugin keeps, and where each one applies.
 *
 * Until now a key's scope was decided by whichever code read it. The same key
 * could hold a global value and one per corporation, and different parts of
 * the plugin read different copies, so a value saved in one place could be
 * ignored by the code that used it. This list says it once, for every key.
 *
 * - INSTALL: one value for the whole install, kept with no corporation.
 * - PROFILE: one value per tax profile. The Global profile, kept with no
 *   corporation, taxes everyone whose corporation is not configured; each
 *   configured corporation keeps its own.
 * - STATE: the plugin's own records, such as cutover dates and last-sent
 *   times. One global row each, never on the settings page, never exported.
 * - LEGACY: no longer used, or being replaced. Read as before until the
 *   release that retires them, then archived and cleared.
 */
final class SettingsRegistry
{
    public const INSTALL = 'install';
    public const PROFILE = 'profile';
    public const STATE = 'state';
    public const LEGACY = 'legacy';

    /** @var array<string, string> key => scope */
    private const SCOPES = [
        // Setup
        'general.moon_owner_corporation_id' => self::INSTALL,
        'general.configured_corporations' => self::INSTALL,

        // Tax profiles: what is taxed and at what rate, how often bills go out,
        // and the prefix on their payment codes
        'tax_rates.moon_ore.r4' => self::PROFILE,
        'tax_rates.moon_ore.r8' => self::PROFILE,
        'tax_rates.moon_ore.r16' => self::PROFILE,
        'tax_rates.moon_ore.r32' => self::PROFILE,
        'tax_rates.moon_ore.r64' => self::PROFILE,
        'tax_rates.ore' => self::PROFILE,
        'tax_rates.ice' => self::PROFILE,
        'tax_rates.gas' => self::PROFILE,
        'tax_rates.abyssal_ore' => self::PROFILE,
        'tax_rates.triglavian_ore' => self::PROFILE,
        'tax_selector.all_moon_ore' => self::PROFILE,
        'tax_selector.only_corp_moon_ore' => self::PROFILE,
        'tax_selector.no_moon_ore' => self::PROFILE,
        'tax_selector.ore' => self::PROFILE,
        'tax_selector.ice' => self::PROFILE,
        'tax_selector.gas' => self::PROFILE,
        'tax_selector.abyssal_ore' => self::PROFILE,
        'tax_selector.triglavian_ore' => self::PROFILE,
        'tax_rates.tax_calculation_period' => self::PROFILE,
        'tax_rates.tax_calculation_period_pending' => self::PROFILE,
        'tax_rates.tax_calculation_period_effective_from' => self::PROFILE,
        'tax_rates.tax_code_prefix' => self::PROFILE,

        // Billing
        'payment.minimum_tax_amount' => self::INSTALL,
        'payment.minimum_tax_behavior' => self::INSTALL,
        'exemptions.enabled' => self::INSTALL,
        'exemptions.threshold' => self::INSTALL,
        'exemptions.grace_period_days' => self::INSTALL,
        'tax_rates.tax_payment_deadline_days' => self::INSTALL,
        'tax_rates.send_tax_reminders' => self::INSTALL,
        'tax_rates.tax_reminder_days' => self::INSTALL,
        'tax_rates.tax_code_length' => self::INSTALL,
        'tax_rates.tax_code_expiration_buffer' => self::INSTALL,
        'tax_rates.auto_generate_tax_codes' => self::INSTALL,
        'payment.overdue_paid_threshold_pct' => self::INSTALL,
        'payment.grace_period_hours' => self::INSTALL,

        // Payments: one wallet is read, so one set of rules matches it
        'tax_rates.tax_wallet_division' => self::INSTALL,
        'payment.auto_match_payments' => self::INSTALL,
        'payment.match_tolerance' => self::INSTALL,
        'payment.accept_alt_characters' => self::INSTALL,
        'payment.cascade_remainder' => self::INSTALL,
        'payment.hold_surplus_as_credit' => self::INSTALL,
        'payment.upfront_keyword' => self::INSTALL,
        'payment.refund_keyword' => self::INSTALL,

        // Pricing
        'price_provider' => self::INSTALL,
        'pricing.price_type' => self::INSTALL,
        'pricing.cache_duration' => self::INSTALL,
        'pricing.fallback_to_jita' => self::INSTALL,
        'pricing.fallback_provider' => self::INSTALL,
        'pricing.refining_efficiency' => self::INSTALL,
        'janice_api_key' => self::INSTALL,
        'janice_market' => self::INSTALL,
        'janice_price_method' => self::INSTALL,
        'janice_batch_size' => self::INSTALL,
        'janice_rate_limit_delay' => self::INSTALL,
        'tracked_ore_types' => self::INSTALL,
        'general.default_region_id' => self::INSTALL,
        'general.price_modifier' => self::INSTALL,

        // Moons
        'moon.estimated_chunk_size' => self::INSTALL,

        // Features and automation
        'features.enable_tax_tracking' => self::INSTALL,
        'features.enable_ledger_tracking' => self::INSTALL,
        'features.enable_analytics' => self::INSTALL,
        'features.enable_reports' => self::INSTALL,
        'features.enable_events' => self::INSTALL,
        'features.allow_event_creation' => self::INSTALL,
        'features.enable_moon_tracking' => self::INSTALL,
        'features.theft_overdue_days' => self::INSTALL,
        'features.allow_member_leaderboard' => self::INSTALL,
        'features.show_character_names' => self::INSTALL,
        'features.allow_export_data' => self::INSTALL,
        'features.auto_process_ledger' => self::INSTALL,
        'features.auto_calculate_taxes' => self::INSTALL,
        'features.auto_generate_invoices' => self::INSTALL,
        'features.verify_wallet_transactions' => self::INSTALL,
        'features.enable_upfront_payments' => self::INSTALL,
        'features.ledger_retention_days' => self::INSTALL,
        'features.auto_cleanup_old_data' => self::INSTALL,

        // Notifications and the moon planner's rules
        'notifications.enabled_types' => self::INSTALL,
        'notifications.type_settings' => self::INSTALL,
        'notifications.evemail_enabled' => self::INSTALL,
        'notifications.evemail_sender_character_id' => self::INSTALL,
        'notifications.evemail_sender_character_override' => self::INSTALL,
        'notifications.evemail_types' => self::INSTALL,
        'notifications.slack_enabled' => self::INSTALL,
        'notifications.slack_webhook_url' => self::INSTALL,
        'notifications.slack_types' => self::INSTALL,
        'notifications.metenox_cargo_full_threshold_pct' => self::INSTALL,
        'notifications.min_extraction_gap_hours' => self::INSTALL,
        'notifications.min_refinery_spacing_hours' => self::INSTALL,
        'notifications.moon_not_rescheduled_hours' => self::INSTALL,
        'notifications.moon_not_rescheduled_repeat' => self::INSTALL,
        'notifications.planned_ahead_target' => self::INSTALL,
        'notifications.schedule_needs_filling_hours' => self::INSTALL,
        'notifications.moon_scan_missing_daily' => self::INSTALL,
        'notifications.moon_extraction_fastpoll_mode' => self::INSTALL,

        // Dashboard
        'dashboard_leaderboard_corporation_filter' => self::INSTALL,
        'dashboard_leaderboard_corporation_ids' => self::INSTALL,

        // The plugin's own records
        'classification.epoch' => self::STATE,
        'payment.dedup_epoch' => self::STATE,
        'tax.part_payment_epoch' => self::STATE,
        'theft.counting_from' => self::STATE,
        'pricing.provider_status' => self::STATE,
        'payment.last_outstanding_digest_at' => self::STATE,
        'notifications.schedule_needs_filling_last_sent' => self::STATE,
        'notifications.moon_scan_missing_daily_last_sent' => self::STATE,
        'notifications.slack_legacy_success_count' => self::STATE,
        'notifications.slack_legacy_failure_count' => self::STATE,
        'notifications.slack_legacy_last_success_at' => self::STATE,
        'notifications.slack_legacy_last_failure_at' => self::STATE,
        'notifications.slack_legacy_last_error' => self::STATE,
        'settings.transferred_at' => self::STATE,
        'settings.transfer_report' => self::STATE,

        // Replaced by the Global profile, which taxes everyone whose
        // corporation is not configured
        'guest_tax_rates.moon_ore.r4' => self::LEGACY,
        'guest_tax_rates.moon_ore.r8' => self::LEGACY,
        'guest_tax_rates.moon_ore.r16' => self::LEGACY,
        'guest_tax_rates.moon_ore.r32' => self::LEGACY,
        'guest_tax_rates.moon_ore.r64' => self::LEGACY,
        'guest_tax_rates.ore' => self::LEGACY,
        'guest_tax_rates.ice' => self::LEGACY,
        'guest_tax_rates.gas' => self::LEGACY,
        'guest_tax_rates.abyssal_ore' => self::LEGACY,
        'guest_tax_rates.triglavian_ore' => self::LEGACY,
        'features.allow_guest_mining' => self::LEGACY,

        // Ore is always valued by the minerals it refines into, gas at its own
        // price, so there is nothing left to choose
        'general.ore_valuation_method' => self::LEGACY,
        'pricing.use_refined_value' => self::LEGACY,

        // Nothing reads these
        'general.corporation_id' => self::LEGACY,
        'general.corporation_name' => self::LEGACY,
        'general.corporation_ticker' => self::LEGACY,
        'general.currency_decimals' => self::LEGACY,
        'general.items_per_page' => self::LEGACY,
        'general.compact_mode' => self::LEGACY,
        'general.show_character_portraits' => self::LEGACY,
        'features.allow_public_stats' => self::LEGACY,
        'features.tax_record_retention_days' => self::LEGACY,
        'moon.auto_calculate_values' => self::LEGACY,
        'moon.show_unscanned_moons' => self::LEGACY,
        'notifications.discord_ping_tax_page_url' => self::LEGACY,
        'cache_enabled' => self::LEGACY,
        'cache_duration' => self::LEGACY,
        'cache_statistics' => self::LEGACY,

        // Worked out from the per-type settings on save, read by Diagnostics only
        'notifications.discord_pinging_enabled' => self::LEGACY,
        'notifications.discord_ping_show_amount' => self::LEGACY,

        // Older names and single-choice settings
        'payment.method' => self::LEGACY,
        'payment.wallet_division' => self::LEGACY,
        'tax_rates.tax_payment_method' => self::LEGACY,
        'tax_rates.event_bonus' => self::LEGACY,
        'pricing.manager_core_market' => self::LEGACY,
        'pricing.manager_core_variant' => self::LEGACY,

        // Rows older versions wrote and nothing has read since
        'features.auto_track_event_participation' => self::LEGACY,
        'features.calculate_moon_value' => self::LEGACY,
        'features.extraction_notification_hours' => self::LEGACY,
        'features.notify_extraction_ready' => self::LEGACY,
        'features.track_moon_compositions' => self::LEGACY,
    ];

    /**
     * Where a key applies, or null for a key the plugin does not keep.
     */
    public static function scope(string $key): ?string
    {
        return self::SCOPES[$key] ?? null;
    }

    /**
     * Every key, or every key with the given scope.
     *
     * @return array<int, string>
     */
    public static function keys(?string $scope = null): array
    {
        if ($scope === null) {
            return array_keys(self::SCOPES);
        }

        return array_keys(array_filter(self::SCOPES, fn (string $keyScope) => $keyScope === $scope));
    }
}
