<?php

namespace ScriptDevelop\WhatsappManager\Services\Flows;

use Illuminate\Support\Facades\File;
use Exception;

class FlowCryptoService
{
    protected ?string $privateKey = null;

    /**
     * Constructor vacío — la clave se carga explícitamente con loadFromPath() o loadForAccount().
     * El service provider NO debe registrar esto como singleton con key pre-cargada.
     */
    public function __construct() {}

    /**
     * Carga la clave privada desde un path explícito.
     */
    public function loadFromPath(string $path): static
    {
        if (!File::exists($path)) {
            throw new Exception("No se encontró la llave privada en: {$path}");
        }

        $this->privateKey = File::get($path);
        return $this;
    }

    /**
     * Carga la clave privada para una cuenta específica.
     * Path: storage/app/whatsapp/flows/keys/{accountId}/private.pem
     */
    public function loadForAccount(string $accountId): static
    {
        return $this->loadFromPath(
            storage_path("app/whatsapp/flows/keys/{$accountId}/private.pem")
        );
    }

    /**
     * Verifica que haya una clave cargada. Si no, intenta el path legacy
     * (storage/app/public/whatsapp/flows/keys/private.pem) para backwards compatibility.
     */
    protected function ensureKeyLoaded(): void
    {
        if ($this->privateKey !== null) {
            return;
        }

        $legacyPath = storage_path('app/public/whatsapp/flows/keys/private.pem');

        if (File::exists($legacyPath)) {
            $this->privateKey = File::get($legacyPath);
            return;
        }

        throw new Exception(
            'No hay clave privada cargada. Llamá a loadFromPath() o loadForAccount() antes de desencriptar.'
        );
    }

    /**
     * Desencripta la petición entrante de WhatsApp Flows.
     *
     * Meta requiere RSA-OAEP con SHA-256 para el digest. PHP 8.5+ lo soporta
     * nativamente vía $digest_algo. En versiones anteriores se usa phpseclib3.
     */
    public function decryptRequest(string $encryptedAesKey, string $encryptedFlowData, string $initialVector): array
    {
        $this->ensureKeyLoaded();

        $encodedBinary = base64_decode($encryptedAesKey);
        $decryptedAesKey = null;

        if (version_compare(PHP_VERSION, '8.5.0', '>=')) {
            $keyResource = openssl_pkey_get_private($this->privateKey);
            if (! openssl_private_decrypt($encodedBinary, $decryptedAesKey, $keyResource, OPENSSL_PKCS1_OAEP_PADDING, 'sha256')) {
                throw new Exception('Fallo al desencriptar la llave AES: ' . openssl_error_string());
            }
        } elseif (class_exists('\\phpseclib3\\Crypt\\PublicKeyLoader')) {
            try {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($this->privateKey)
                    ->withHash('sha256')
                    ->withMGFHash('sha256');
                $result = $key->decrypt($encodedBinary);
                if ($result === null || $result === false || $result === '') {
                    throw new Exception('phpseclib3: RSA-OAEP SHA-256 decryption failed');
                }
                $decryptedAesKey = $result;
            } catch (\Throwable $e) {
                throw new Exception('Fallo al desencriptar la llave AES (phpseclib3): ' . $e->getMessage());
            }
        } else {
            $keyResource = openssl_pkey_get_private($this->privateKey);
            if (! openssl_private_decrypt($encodedBinary, $decryptedAesKey, $keyResource, OPENSSL_PKCS1_OAEP_PADDING)) {
                throw new Exception('Fallo al desencriptar la llave AES: ' . openssl_error_string());
            }
        }

        $encryptedDataBinary = base64_decode($encryptedFlowData);
        $iv = base64_decode($initialVector);
        $tag = substr($encryptedDataBinary, -16);
        $ciphertext = substr($encryptedDataBinary, 0, -16);

        $decryptedData = openssl_decrypt(
            $ciphertext, 'aes-128-gcm', $decryptedAesKey,
            OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($decryptedData === false) {
            throw new Exception('Fallo al desencriptar los datos del flujo.');
        }

        return json_decode($decryptedData, true);
    }

    /**
     * Encripta la respuesta para enviarla de vuelta a WhatsApp.
     *
     * Invierte todos los bits del IV antes de encriptar, como requiere Meta
     * en su implementación de WhatsApp Flows.
     */
    public function encryptResponse(array $data, string $aesKey, string $iv): string
    {
        $plainText = json_encode($data);
        $tag = null;

        $decodedIv = base64_decode($iv);
        $flippedIv = '';
        $len = strlen($decodedIv);
        for ($i = 0; $i < $len; $i++) {
            $flippedIv .= chr(ord($decodedIv[$i]) ^ 0xFF);
        }

        $ciphertext = openssl_encrypt(
            $plainText, 'aes-128-gcm', base64_decode($aesKey),
            OPENSSL_RAW_DATA, $flippedIv, $tag
        );

        return base64_encode($ciphertext . $tag);
    }
}
