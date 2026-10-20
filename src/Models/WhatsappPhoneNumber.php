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

    protected $table = 'whatsapp_phone_numbers';
    protected $primaryKey = 'phone_number_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'whatsapp_business_account_id',
        'whatsapp_business_profile_id',
        'display_phone_number',
        'country_code',
        'phone_number',
        'api_phone_number_id',
        'catalog_id',
        'verified_name',
        'new_display_name',
        'new_name_status',

        'code_verification_status',
        'quality_rating',
        'name_status',
        'platform_type',
        'throughput',
        'webhook_configuration',
        'is_official',
        'oba_status',
        'is_pin_enabled',

        // Nuevos campos
        'status',
        'disconnected_at',
        'fully_removed_at',
        'disconnection_reason',

        // Estado real reportado por Meta (no confundir con el status interno)
        'meta_connection_status',
        'meta_status_checked_at',
    ];

    protected $casts = [
        'throughput' => 'array',
        'webhook_configuration' => 'array',
        'disconnected_at' => 'datetime',
        'fully_removed_at' => 'datetime',
        'meta_status_checked_at' => 'datetime',
    ];

    // Scopes para filtrar por estado
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }

    public function scopeRemoved($query)
    {
        return $query->where('status', 'removed');
    }

    // Métodos de ayuda para el estado
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }

    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }

    /**
     * Indica si Meta reporta el número como CONNECTED (registrado y listo
     * para enviar/recibir mensajes por la Cloud API).
     */
    public function isMetaConnected(): bool
    {
        return $this->meta_connection_status === 'CONNECTED';
    }

    /**
     * Indica si el número necesita completar el registro en la Cloud API
     * (Meta reporta PENDING). Otros estados (OFFLINE, BANNED, RESTRICTED,
     * DEREGISTERED, MIGRATED) requieren acciones distintas y no cuentan
     * como "pendiente de registro".
     */
    public function needsRegistration(): bool
    {
        return $this->meta_connection_status === 'PENDING';
    }

    public function messages()
    {
        return $this->hasMany(config('whatsapp.models.message'), 'whatsapp_phone_id');
    }

    public function businessAccount()
    {
        return $this->belongsTo(config('whatsapp.models.business_account'), 'whatsapp_business_account_id');
    }

    public function businessProfile()
    {
        return $this->belongsTo(
            config('whatsapp.models.business_profile'),
            'whatsapp_business_profile_id',
            'whatsapp_business_profile_id'
        );
    }

    public function contacts()
    {
        return $this->hasMany(config('whatsapp.models.contact'))
                    ->whereHas('whatsapp_messages', function ($query) {
                        $query->where('whatsapp_phone_id', $this->phone_number_id);
                    })
                    ->distinct();
    }

    /**
     * Relación muchos a muchos con contactos a través de la tabla pivote personalizada.
     * Esta relación permite almacenar datos adicionales del contacto para este número.
     */
    public function contactProfiles()
    {
        return $this->belongsToMany(Contact::class, 'whatsapp_contact_profiles', 'phone_number_id', 'contact_id')
                    ->using(WhatsappContactProfile::class)
                    ->withPivot([
                        'profile_picture',
                        'alias',
                        'contact_name',
                        'first_name',
                        'last_name',
                        'middle_name',
                        'suffix',
                        'prefix',
                        'organization',
                        'department',
                        'title',
                        'birthday',
                        'last_interaction_at',
                        'metadata',
                    ])
                    ->withTimestamps();
    }

    /**
     * Acceso directo a los registros pivote (perfiles).
     */
    public function contactProfilePivots()
    {
        return $this->hasMany(WhatsappContactProfile::class, 'phone_number_id');
    }

    public function qrCodes()
    {
        return $this->hasMany(config('whatsapp.models.qr_code'), 'phone_number_id', 'phone_number_id');
    }

    public function blockedUsers()
    {
        return $this->hasMany(config('whatsapp.models.blocked_user'), 'phone_number_id');
    }
}