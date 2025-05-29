<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ScriptDevelop\WhatsappManager\Enums\StepType; 

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class FlowStep extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'flow_steps';

    protected $primaryKey = 'step_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flow_id',
        'order',
        'name',
        'step_type',
        'validation_rules',
        'max_attempts',
        'retry_message',
        'api_config',
        'failure_action',
        'failure_step_id',
        'is_terminal',
        'is_entry_point',
        'failure_step_id', // Paso para reintentos
        'variable_name',   // Variable a recolectar
        'storage_scope'    // 'global' o 'step'
    ];

    protected $casts = [
        'step_type' => StepType::class,
        'validation_rules' => 'json', // Mejor soporte para reglas
        'api_config' => 'array',
        'is_terminal' => 'boolean',
    ];

    // Relaciones

    // FlowStep pertenece a un flujo
    public function flow()
    {
        return $this->belongsTo(Flow::class, 'flow_id', 'flow_id');
    }

    public function messages() {
        return $this->hasMany(StepMessage::class, 'flow_step_id');
    }

    public function variables() {
        return $this->hasMany(StepVariable::class, 'flow_step_id');
    }

    // Relación con Respuestas de usuario
    public function userResponses()
    {
        return $this->hasMany(UserResponse::class, 'flow_step_id', 'step_id');
    }

    public function transitions()
    {
        return $this->hasMany(StepTransition::class, 'from_step_id', 'step_id');
    }
}
