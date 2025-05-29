<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class WhatsappBusinessProfile extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_business_profiles';

    protected $primaryKey = 'whatsapp_business_profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'whatsapp_business_profile_id',
        'profile_picture_url',
        'about',
        'address',
        'description',
        'email',
        'vertical',
        'messaging_product',
    ];

    public function websites()
    {
        return $this->hasMany(Website::class, 'whatsapp_business_profile_id');
    }

    public function phoneNumber()
    {
        return $this->hasOne(
            WhatsappPhoneNumber::class,
            'whatsapp_business_profile_id', // Clave foránea en phone_numbers
            'whatsapp_business_profile_id'  // Clave primaria en este modelo
        );
    }
}
