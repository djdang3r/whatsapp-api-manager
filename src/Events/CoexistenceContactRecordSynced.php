<?php

namespace ScriptDevelop\WhatsappManager\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara por cada contacto persistido durante la sincronización de
 * coexistencia (`smb_app_state_sync`). Incluye el número de WhatsApp que
 * originó la sincronización para que el consumidor resuelva su empresa.
 */
class CoexistenceContactRecordSynced
{
    use Dispatchable;

    public function __construct(
        public Model $contact,
        public string $action = 'update',
        public ?string $phoneNumberId = null,
    ) {}
}
