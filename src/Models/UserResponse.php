<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class UserResponse extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_responses';

    protected $primaryKey = 'response_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'flow_step_id',
        'message_id',
        'field_name',
        'field_value',
        'contact_id',
    ];

    protected $casts = [
        'field_value' => 'array',
    ];

    // Relaciones

    // UserResponse pertenece a una sesión (chat_session)
    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }

    // UserResponse pertenece a un paso del flujo
    public function flowStep()
    {
        return $this->belongsTo(FlowStep::class, 'flow_step_id', 'step_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }
}
