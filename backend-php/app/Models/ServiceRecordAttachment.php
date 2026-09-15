<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo or video captured against a Service Record from the Mobile
 * Web App. Mirrors backend/app/models/attachments.py.
 *
 * The file lives on disk under config('websoft.uploads_dir'); only the
 * metadata is here. Deleting soft-deletes the row and leaves the file,
 * the same as the generic document panel.
 */
class ServiceRecordAttachment extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const KIND_WORK_PHOTO = 'work_photo';

    public const KIND_WORK_VIDEO = 'work_video';

    /** Watermarked at capture so it cannot be reused on another record. */
    public const KIND_CHOP_PHOTO = 'chop_photo';

    protected $fillable = [
        'company_id', 'service_record_id', 'uploaded_by_user_id', 'kind',
        'original_filename', 'stored_filename', 'content_type', 'file_size_bytes',
        'is_deleted',
    ];

    protected $attributes = ['is_deleted' => false];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'is_deleted' => 'boolean',
        'uploaded_at' => 'datetime',
    ];

    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }
}
