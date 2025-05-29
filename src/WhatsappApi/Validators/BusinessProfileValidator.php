<?php

namespace ScriptDevelop\WhatsappManager\WhatsappApi\Validators;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use ScriptDevelop\WhatsappManager\Exceptions\InvalidApiResponseException;

class BusinessProfileValidator
{
    protected $rules = [
        'about' => 'nullable|string|max:512',
        'address' => 'nullable|string|max:256',
        'description' => 'nullable|string|max:512',
        'email' => 'nullable|email|max:128', // Hacer explícitamente nullable
        'profile_picture_url' => 'nullable|url|max:512',
        'vertical' => 'nullable|string|in:UNDEFINED,OTHER,PROFESSIONAL_SERVICES,PROF_SERVICES,ENTERTAIN,EVENT_PLAN',
        'websites' => 'nullable|array',
        'websites.*' => 'url|max:512',
        'messaging_product' => 'required|string|in:whatsapp',
        'quality_rating' => 'nullable|string|in:GREEN,YELLOW,RED'
    ];

    /**
     * @throws InvalidApiResponseException
     */
    public function validate(array $profileData): array
    {   
        // Si el perfil viene dentro de un campo 'data', extraerlo
        if (isset($profileData['data'])) {
            $profileData = $profileData['data'][0] ?? []; // Extraer el primer perfil
        }

        // Verificar si el perfil está vacío
        if (empty($profileData)) {
            throw new InvalidApiResponseException("La respuesta del perfil está vacía");
        }

        Log::channel('whatsapp')->debug('Datos recibidos en el validador:', $profileData);

        $validator = Validator::make($profileData, $this->rules);

        if ($validator->fails()) {
            Log::channel('whatsapp')->error('Errores de validación:', $validator->errors()->toArray());
            throw InvalidApiResponseException::fromValidationError(
                $validator->errors()->first(),
                $validator->errors()->toArray()
            );
        }

        return $this->formatValidData($profileData);
    }

    private function formatValidData(array $data): array
    {
        return [
            'about' => $data['about'] ?? null,
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'email' => $data['email'] ?? null,
            'profile_picture_url' => $data['profile_picture_url'] ?? null,
            'vertical' => $data['vertical'] ?? 'OTHER',
            'messaging_product' => $data['messaging_product'] ?? 'whatsapp',
            'websites' => $data['websites'] ?? [] // Asegurar array vacío si no hay websites
        ];
    }

    private function parseProfilePicture(array $data): ?string
    {
        $url = $data['profile_picture_url'] ?? null;
        return is_array($url) ? ($url['url'] ?? null) : $url;
    }

    private function parseWebsites(array $websites): array
    {
        return array_map(function ($website) {
            return is_array($website) 
                ? ['url' => $website['url'], 'type' => $website['type'] ?? 'WEB']
                : ['url' => $website, 'type' => 'WEB'];
        }, $websites);
    }

    // Nuevo método para obtener websites
    public function extractWebsites(array $data): array
    {
        return array_map(function ($website) {
            return [
                'website' => is_array($website) 
                    ? $website['url'] 
                    : $website
            ];
        }, $data['websites'] ?? []);
    }
}
