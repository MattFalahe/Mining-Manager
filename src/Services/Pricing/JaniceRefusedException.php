<?php

namespace MiningManager\Services\Pricing;

use Exception;

/**
 * Janice turned the request away rather than failing to answer it.
 *
 * A 401 or 403 means the key is wrong or has been blocked, and a 429 means we
 * have asked too often. None of those get better by asking again in smaller
 * pieces, which is the traffic that gets a key blocked in the first place, so
 * this stops the refresh instead of retrying.
 */
class JaniceRefusedException extends Exception
{
}
