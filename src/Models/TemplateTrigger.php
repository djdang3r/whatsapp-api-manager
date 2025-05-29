<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class TemplateTrigger extends Model
{
    use HasFactory, SoftDeletes, GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'template_triggers';

    protected $primaryKey = 'template_trigger_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'template_name',
        'language',
        'variables'
    ];

    protected $casts = [
        'variables' => 'array'
    ];

    public function trigger()
    {
        return $this->morphOne(FlowTrigger::class, 'triggerable');
    }
}
