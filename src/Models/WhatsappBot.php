<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class WhatsappBot extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_bots';

    protected $primaryKey = 'whatsapp_bot_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'phone_number_id',
        'default_flow_id',
        'bot_name',
        'description',
        'is_enable',
        'on_failure',
        'failure_message',
        'bot_color',
        'mark_messages_as_read'
    ];

    public function phoneNumber()
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'phone_number_id', 'phone_number_id');
    }

    public function defaultFlow()
    {
        return $this->belongsTo(Flow::class, 'default_flow_id', 'flow_id');
    }

    // Relación muchos a muchos con flujos
    public function flows()
    {
        return $this->belongsToMany(
            Flow::class,
            'bot_flow', // Nombre de la tabla pivote
            'whatsapp_bot_id', // FK en bot_flow para el bot
            'flow_id' // FK en bot_flow para el flujo
        );
    }
}
