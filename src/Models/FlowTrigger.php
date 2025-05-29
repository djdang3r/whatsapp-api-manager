<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class FlowTrigger extends Model
{
    use HasFactory, SoftDeletes, GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'flow_triggers';

    protected $primaryKey = 'trigger_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flow_id',
        'type',
        'triggerable_id',
        'triggerable_type'
    ];

    // Relación inversa: un trigger pertenece a un flujo
    public function flow()
    {
        return $this->belongsTo(Flow::class, 'flow_id', 'flow_id');
        
    }

    public function triggerable()
    {
        return $this->morphTo();
    }
}
