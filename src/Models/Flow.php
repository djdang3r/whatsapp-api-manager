<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class Flow extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'flows';

    protected $primaryKey = 'flow_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'type', // 'inbound', 'outbound' o 'hybrid'
        'trigger_mode', // 'any' o 'all'
        
        'entry_point_id',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'trigger_keywords' => 'array',
        'is_case_sensitive' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Validación al guardar: si el flujo no es sensible a mayúsculas, se guardan las palabras clave en minúsculas.
    protected static function booted()
    {
        static::saving(function ($flow) {
            if (!$flow->is_case_sensitive && !empty($flow->trigger_keywords)) {
                $flow->trigger_keywords = array_map('strtolower', $flow->trigger_keywords);
            }
        });
    }


   

    // Relaciones

    // Flow pertenece a un bot
    public function bots()
    {
        return $this->belongsToMany(
            WhatsappBot::class,
            'bot_flow',
            'flow_id',
            'whatsapp_bot_id'
        )->withTimestamps();
    }

    // Flow tiene muchos pasos
    public function steps()
    {
        return $this->hasMany(FlowStep::class, 'flow_id', 'flow_id');
    }

    public function triggers()
    {
        return $this->hasMany(FlowTrigger::class, 'flow_id', 'flow_id');
    }

    public function variables()
    {
        return $this->hasMany(FlowVariable::class, 'flow_id', 'flow_id');
    }
}
