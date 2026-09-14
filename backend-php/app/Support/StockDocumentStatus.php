<?php

namespace App\Support;

/**
 * General document status for a Goods Receive Note, Goods Transfer
 * Note and Goods Return Note. Mirrors
 * backend/app/models/inventory.py's single shared `DocumentStatus`
 * enum -- one class here rather than the same three constants repeated
 * on three models, so the two backends stay diffable and a value can
 * never drift between the documents.
 *
 * The string values are the API contract: the existing frontend reads
 * them straight off the wire.
 */
final class StockDocumentStatus
{
    /** Created but not yet posted to stock -- nothing has moved. */
    public const DRAFT = 'draft';

    /** Posted: stock levels and the stock_movements ledger are updated. */
    public const CONFIRMED = 'confirmed';

    /**
     * Declared by the Python enum but never set by any of its routes
     * (there is no cancel endpoint in either backend). Kept so the
     * value set matches rather than quietly shrinking.
     */
    public const CANCELLED = 'cancelled';
}
