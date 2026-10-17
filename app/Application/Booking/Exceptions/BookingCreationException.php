<?php

namespace App\Application\Booking\Exceptions;

use RuntimeException;

/**
 * BookingCreationException
 *
 * Signals that a campaign booking could not be created for a *business* reason,
 * carrying the exact message, HTTP status and error payload that the public API
 * has always returned for that reason.
 *
 * WHY AN EXCEPTION RATHER THAN A RESULT OBJECT (Phase 0.13):
 * `BookingController::storeCampaign()` originally returned
 * `$this->errorResponse(...)` from inside its `DB::transaction` closure. An HTTP
 * response cannot cross into the application layer, so the four business
 * rejections had to be inlined in the controller.
 *
 * The conversion is safe because **every one of those four rejections happens
 * before the first write** in the transaction (the sold-out gate, the duration
 * gate, the eligibility gate and the pricing gate all run ahead of
 * `Booking::create()`). Returning a value from inside `DB::transaction` commits;
 * throwing rolls back. For a transaction that has written nothing, the two are
 * observably identical — the only effect either way is releasing the inventory
 * row locks taken at the top of the transaction.
 *
 * The status code and error payload are carried verbatim so the response
 * envelope (`status` / `message` / `errors`) is byte-for-byte unchanged.
 */
class BookingCreationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $errors = null
    ) {
        parent::__construct($message);
    }
}
