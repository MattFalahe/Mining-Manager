<?php

namespace MiningManager\Services\Tax\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared "which characters belong to the same player" lookup.
 *
 * This used to exist as two hand-synced copies, one in WalletTransferService
 * and one in VerifyWalletPaymentsCommand, with a comment on each asking the
 * next person to keep them aligned. They drifted anyway. Anything that needs
 * to widen a payment match across a player's alts pulls it from here instead.
 */
trait ResolvesCharacterOwnership
{
    /**
     * Every character belonging to the same player as the given one.
     *
     * The SeAT model: refresh_tokens.user_id is the cross-character link, so
     * every character a player has authenticated against the install shares a
     * user_id. Characters sharing a user_id are alts of the same player.
     *
     * Falls back to a single-element list (just the input character) when the
     * character isn't registered, or when the lookup fails for any reason. The
     * result always contains the input character, so it is safe to hand
     * straight to a whereIn().
     *
     * @param  int  $characterId
     * @return array<int>
     */
    protected function getCharacterIdsForUserOf(int $characterId): array
    {
        try {
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if ($userId === null) {
                // Unregistered character (guest miner, revoked token). Nothing
                // to widen to, so match strictly on the character itself.
                return [$characterId];
            }

            $charIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (!in_array($characterId, $charIds, true)) {
                $charIds[] = $characterId;
            }

            return $charIds;
        } catch (\Exception $e) {
            Log::warning('Mining Manager: character ownership lookup failed, falling back to strict character match', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
            ]);

            return [$characterId];
        }
    }

    /**
     * True when both characters belong to the same player, or are the same
     * character. Boolean wrapper for "is this payer allowed to settle this
     * invoice?" gates.
     */
    protected function sharesSeatUser(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return in_array($b, $this->getCharacterIdsForUserOf($a), true);
    }

    /**
     * The character ids a payment from $payerCharacterId may settle invoices
     * for, honouring the payment.accept_alt_characters setting.
     *
     * @param  int  $payerCharacterId
     * @param  bool  $acceptAlts
     * @return array<int>
     */
    protected function eligibleCharacterIds(int $payerCharacterId, bool $acceptAlts): array
    {
        return $acceptAlts
            ? $this->getCharacterIdsForUserOf($payerCharacterId)
            : [$payerCharacterId];
    }
}
