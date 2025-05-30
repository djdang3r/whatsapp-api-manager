<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class ChatSession extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    protected $table = 'whatsapp_chat_sessions';
    protected $primaryKey = 'session_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'contact_id',
        'whatsapp_phone_id',
        'assigned_bot_id',
        'flow_id',
        'current_step_id',
        'status',
        'context',
        'assigned_at',
        'assigned_agent_id',
        'flow_status',
        'message_queue',
        'current_queue_position',
        'attempt_count',
        'collected_variables',
    ];

    protected $casts = [
        'context' => 'array',
        'assigned_at' => 'datetime',
        'message_queue' => 'array',
        'collected_data' => 'array'
    ];

    // Relaciones

    // ChatSession pertenece a un contacto
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }

    // ChatSession pertenece a un número de WhatsApp
    public function whatsappPhone()
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_id', 'phone_number_id');
    }

    // ChatSession puede estar asignada a un bot
    // public function assignedBot()
    // {
    //     return $this->belongsTo(WhatsappBot::class, 'assigned_bot_id', 'whatsapp_bot_id');
    // }

    // ChatSession puede estar asignada a un agente (usuario)
    public function assignedAgent()
    {
        return $this->belongsTo(
            config('whatsapp-manager.models.user_model'), // Configuración
            'assigned_agent_id'
        );
    }

    public function bot()
    {
        return $this->belongsTo(
            WhatsappBot::class, 
            'assigned_bot_id', // Foreign key en chat_sessions
            'whatsapp_bot_id'  // Primary key en whatsapp_bots
        )->with('phoneNumber'); // Cargar siempre la relación phoneNumber
    }

    // ChatSession pertenece a un flujo
    public function flow()
    {
        return $this->belongsTo(Flow::class, 'flow_id', 'flow_id');
    }

    // ChatSession se encuentra en un paso actual del flujo
    public function currentStep()
    {
        return $this->belongsTo(FlowStep::class, 'current_step_id', 'step_id');
    }

    public function responses()
    {
        return $this->hasMany(UserResponse::class, 'session_id', 'session_id');
    }

    public function getIsActiveAttribute(): bool {
        return $this->status === 'active';
    }

    public function getProgressPercentageAttribute(): float {
        $totalSteps = $this->flow->steps()->count();
        return $totalSteps > 0 
            ? ($this->completed_steps / $totalSteps) * 100 
            : 0;
    }

    public function scopeActive($query) {
        return $query->where('last_activity', '>', now()->subHours(24));
    }

    public function loadCriticalRelations()
    {
        $this->load([
            'currentStep',
            'flow.entryPoint',
            'bot.phoneNumber',
            'whatsappPhone'
        ]);
    }
}
