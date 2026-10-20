<?php

namespace ScriptDevelop\WhatsappManager\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara por cada mensaje histórico persistido durante la sincronización
 * de coexistencia (`history`). No se broadcasta: el historial puede ser
 * voluminoso y el consumidor decide cómo ingerirlo (normalmente en cola).
 */
class CoexistenceHistoryMessageReceived
{
    use Dispatchable;

    public function __construct(
        public Model $contact,
        public Model $message,
        public int $phase = 0,
    ) {}
}
