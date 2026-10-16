<?php

namespace MiningManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MiningManager\Services\Character\CharacterNames;

/**
 * A chart or table a page loads after itself can show characters as in
 * progress too. This tells the page which ones, so it can say so and refresh
 * once they are in, the same as when the page itself showed them.
 */
class PendingCharactersHeader
{
    public const HEADER = 'X-Mining-Manager-Pending-Characters';

    /** The pending endpoint reads this many at most. */
    private const LIMIT = 500;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->ajax() || $request->wantsJson()) {
            $pending = array_slice(app(CharacterNames::class)->shownPending(), 0, self::LIMIT);
            if ($pending) {
                $response->headers->set(self::HEADER, implode(',', $pending));
            }
        }

        return $response;
    }
}
