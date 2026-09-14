<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Picture / document attachment on a stock item. Mirrors
 * backend/app/models/inventory.py's StockItemAttachment.
 *
 * The file itself lives on disk under config('websoft.uploads_dir'),
 * not in the database -- see
 * App\Http\Controllers\Api\StockItemController::attachmentDir().
 * `stored_filename` is always this row's own uuid plus the uploaded
 * extension, never the user-supplied name.
 */
class StockItemAttachment extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'id', 'stock_item_id', 'filename', 'stored_filename', 'content_type', 'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
