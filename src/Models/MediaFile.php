<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class MediaFile extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'media_files';

    protected $primaryKey = 'media_file_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'media_type',
        'file_name',
        'mime_type',
        'sha256',
        'url',
        'media_id',
        'file_size',
        'animated',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
