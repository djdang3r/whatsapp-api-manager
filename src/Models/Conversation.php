<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'conversations';

    protected $primaryKey = 'conversation_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'wa_conversation_id',
        'expiration_timestamp',
        'origin',
        'pricing_model',
        'billable',
        'category',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }
}
