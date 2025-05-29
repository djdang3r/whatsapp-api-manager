<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class WhatsappPhoneNumber extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_phone_numbers';

    protected $primaryKey = 'phone_number_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'whatsapp_business_account_id',
        'whatsapp_business_profile_id',
        'whatsapp_bot_id',
        'display_phone_number',
        'country_code',
        'phone_number',
        'api_phone_number_id',
        'verified_name',

        'code_verification_status',
        'quality_rating',
        'platform_type',
        'throughput',
        'webhook_configuration'
    ];

    protected $casts = [
        'throughput' => 'array',
        'webhook_configuration' => 'array',
    ];

    public function bots()
    {
        return $this->hasMany(WhatsappBot::class, 'phone_number_id', 'phone_number_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'whatsapp_phone_id');
    }

    public function businessAccount()
    {
        return $this->belongsTo(WhatsappBusinessAccount::class, 'whatsapp_business_account_id');
    }

    public function businessProfile()
    {
        return $this->belongsTo(
            WhatsappBusinessProfile::class,
            'whatsapp_business_profile_id', 
            'whatsapp_business_profile_id'
        );
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class)
                    ->whereHas('messages', function ($query) {
                        $query->where('whatsapp_phone_id', $this->phone_number_id);
                    })
                    ->distinct();
    }

    // Relación con Sesiones de chat
    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class, 'whatsapp_phone_id');
    }
}
