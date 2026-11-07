<?php

namespace MiningManager\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\EventMiningRecord;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Character\CharacterNames;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Ledger\PersonalMiningReconciler;

/**
 * Who took part in an event, as people rather than characters, for the event
 * page: each account with its characters' event mining, whether it signed
 * up, and the discount its days actually got from the event. A character
 * with no SeAT account stands on its own.
 */
class EventPeople
{
    public const SIGNED_UP = 'signed_up';
    public const SIGNED_UP_WAITING = 'signed_up_waiting';
    public const ADDED = 'added';
    public const NOT_SIGNED_UP = 'not_signed_up';

    /**
     * @return array<int, array{main: int, name: string, characters: array<int, array{id: int, name: string, value: float}>, value: float, qualifying: float, discount: float, status: string, signed_up_at: ?Carbon}>
     */
    public function people(MiningEvent $event): array
    {
        $rules = MiningEvent::hasRules();
        $mined = EventMiningRecord::where('event_id', $event->id)
            ->selectRaw('character_id, SUM(quantity) as quantity, SUM(value_isk) as value')
            ->selectRaw($rules ? 'SUM(CASE WHEN qualifies THEN value_isk ELSE 0 END) as qualifying' : 'SUM(value_isk) as qualifying')
            ->groupBy('character_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->character_id);

        $signups = $rules ? $event->signups()->get()->keyBy(fn ($signup) => (int) $signup->user_id) : collect();

        $characters = $mined->keys()->all();
        $accounts = MiningEvent::accountsOf($characters);
        $mains = DB::table('users')
            ->whereIn('id', array_values(array_unique(array_merge(array_values($accounts), $signups->keys()->all()))))
            ->pluck('main_character_id', 'id');

        $discounts = app(LedgerSummaryService::class)->getEventSavingsByCharacter($event->id, $characters, $event->start_time, $event->end_time);

        $names = app(CharacterNames::class);
        $names->preload(array_merge($characters, $mains->filter()->values()->all(), $signups->pluck('character_id')->filter()->values()->all()));

        $groups = [];

        foreach ($mined as $characterId => $row) {
            $user = $accounts[$characterId] ?? null;
            $key = $user !== null ? "u{$user}" : "c{$characterId}";

            $groups[$key] ??= [
                'user' => $user,
                'main' => $user !== null ? (int) ($mains[$user] ?? 0) : $characterId,
                'characters' => [],
                'value' => 0.0,
                'qualifying' => 0.0,
                'discount' => 0.0,
            ];
            $groups[$key]['characters'][] = ['id' => $characterId, 'name' => $names->name($characterId), 'value' => (float) $row->value];
            $groups[$key]['value'] += (float) $row->value;
            $groups[$key]['qualifying'] += (float) $row->qualifying;
            $groups[$key]['discount'] += $discounts[$characterId] ?? 0.0;
        }

        // Accounts that signed up and have not mined for it yet.
        foreach ($signups as $user => $signup) {
            $groups["u{$user}"] ??= [
                'user' => $user,
                'main' => (int) ($mains[$user] ?? $signup->character_id ?? 0),
                'characters' => [],
                'value' => 0.0,
                'qualifying' => 0.0,
                'discount' => 0.0,
            ];
        }

        $people = [];

        foreach ($groups as $group) {
            $signup = $group['user'] !== null ? $signups->get($group['user']) : null;
            $main = $group['main'] ?: ($group['characters'][0]['id'] ?? 0);

            if ($signup) {
                $status = $group['characters'] ? self::SIGNED_UP : self::SIGNED_UP_WAITING;
            } else {
                $status = $event->needsSignUp() ? self::NOT_SIGNED_UP : self::ADDED;
            }

            usort($group['characters'], fn ($a, $b) => $b['value'] <=> $a['value']);

            $people[] = [
                'main' => $main,
                'name' => $main ? $names->name($main) : ($group['characters'][0]['name'] ?? ''),
                'characters' => $group['characters'],
                'value' => round($group['value'], 2),
                'qualifying' => round($group['qualifying'], 2),
                'discount' => round($group['discount'], 2),
                'status' => $status,
                'signed_up_at' => $signup ? ($signup->signed_up_at ?? $signup->created_at) : null,
            ];
        }

        usort($people, fn ($a, $b) => [$b['value'], $a['name']] <=> [$a['value'], $b['name']]);

        return $people;
    }

    /**
     * The event's mining by day, oldest first.
     *
     * @return array<string, array{value: float, qualifying: float, pilots: int}>
     */
    public function byDay(MiningEvent $event): array
    {
        return $this->grouped($event, 'mining_date')
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->bucket)->toDateString() => $this->figures($row)])
            ->sortKeys()
            ->all();
    }

    /**
     * The event's refinery mining by refinery.
     *
     * @return array<int, array{value: float, qualifying: float, pilots: int}>
     */
    public function byRefinery(MiningEvent $event): array
    {
        return $this->grouped($event, 'observer_id', fn ($query) => $query->whereNotNull('observer_id'))
            ->mapWithKeys(fn ($row) => [(int) $row->bucket => $this->figures($row)])
            ->all();
    }

    /**
     * Until when refinery reports can still add to an ended event: the
     * every-minute update keeps gathering it for a few days, because a
     * refinery reports a day only once it is over. Null once that is past,
     * or for an event that counts no moon ore.
     */
    public function stillReportingUntil(MiningEvent $event): ?Carbon
    {
        $moonOre = array_filter($event->oreCategories(), fn ($category) => str_starts_with($category, 'moon_'));

        if (!$moonOre || $event->status === 'cancelled' || $event->status === 'planned') {
            return null;
        }

        if ($event->end_time === null) {
            return null;
        }

        $until = $event->end_time->copy()->addDays(PersonalMiningReconciler::DEFAULT_DAYS);

        return $until->isFuture() ? $until : null;
    }

    private function grouped(MiningEvent $event, string $column, ?callable $narrow = null)
    {
        $query = EventMiningRecord::where('event_id', $event->id)
            ->selectRaw("{$column} as bucket, SUM(value_isk) as value, COUNT(DISTINCT character_id) as pilots")
            ->selectRaw(MiningEvent::hasRules() ? 'SUM(CASE WHEN qualifies THEN value_isk ELSE 0 END) as qualifying' : 'SUM(value_isk) as qualifying')
            ->groupBy($column);

        if ($narrow) {
            $narrow($query);
        }

        return $query->get();
    }

    private function figures($row): array
    {
        return [
            'value' => round((float) $row->value, 2),
            'qualifying' => round((float) $row->qualifying, 2),
            'pilots' => (int) $row->pilots,
        ];
    }
}
