<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class StepMessage extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'step_messages';

    protected $primaryKey = 'message_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flow_step_id',
        'message_type',
        'content',
        'media_file_id',
        'delay_seconds',
        'order',
        'variables_used'
    ];

    protected $casts = [
        'variables_used' => 'array'
    ];

    public function compileContent($variables) {
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($variables) {
            return $variables[$matches[1]] ?? $matches[0];
        }, $this->content);
    }

    public function media() {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }
}
