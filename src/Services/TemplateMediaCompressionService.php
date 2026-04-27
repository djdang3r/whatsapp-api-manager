<?php

namespace ScriptDevelop\WhatsappManager\Services;

use Illuminate\Support\Facades\Log;

class TemplateMediaCompressionService
{
    /**
     * Comprime un archivo de media (video/imagen) solo si excede el tamaño máximo.
     *
     * @return array{success: bool, compressed: bool, final_size: int|null, message: string|null}
     */
    public function compressIfNeeded(string $filePath, string $mediaType, int $maxBytes = 16777216, int $maxAttempts = 3): array
    {
        $mediaType = strtolower($mediaType);
        $currentSize = filesize($filePath);

        if( config('whatsapp.allow_compression_multimedia_template', false) === false ) {
            Log::channel('whatsapp')->info('La compresión de multimedia para plantillas está deshabilitada en la configuración. Se omite la compresión.', [
                'file_path' => $filePath,
                'media_type' => $mediaType,
                'current_size' => $currentSize,
            ]);
            return [
                'success' => true,
                'compressed' => false,
                'final_size' => $currentSize,
                'message' => null,
            ];
        }

        //Esto se hace en el job, pero se deja aquí para poder usar esta función de forma independiente si se desea.
        /*if( empty($filePath) || empty($mediaType) ) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => null,
                'message' => 'Falta la ruta del archivo o el tipo de media.',
            ];
        }

        if (!is_file($filePath)) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => null,
                'message' => "El archivo no existe para comprimir: {$filePath}",
            ];
        }

        if ($currentSize === false) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => null,
                'message' => "No se pudo determinar el tamaño del archivo: {$filePath}",
            ];
        }

        if ($currentSize <= $maxBytes) {
            return [
                'success' => true,
                'compressed' => false,
                'final_size' => $currentSize,
                'message' => null,
            ];
        }*/

        if (!in_array($mediaType, ['video', 'image'], true)) {
            return [
                'success' => true,
                'compressed' => false,
                'final_size' => $currentSize,
                'message' => null,
            ];
        }

        if ($mediaType === 'video') {
            return $this->compressVideo($filePath, $maxBytes, $maxAttempts);
        }

        return $this->compressImage($filePath, $maxBytes, $maxAttempts);
    }

    /**
     * @return array{success: bool, compressed: bool, final_size: int|null, message: string|null}
     */
    protected function compressVideo(string $filePath, int $maxBytes, int $maxAttempts): array
    {
        if (!$this->isFfmpegAvailable()) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => $this->safeFileSize($filePath),
                'message' => 'No se puede comprimir video: ffmpeg no está disponible en el servidor.',
            ];
        }

        $profiles = [
            ['video_bitrate' => '1400k', 'audio_bitrate' => '96k', 'preset' => 'medium'],
            ['video_bitrate' => '900k', 'audio_bitrate' => '64k', 'preset' => 'slow'],
            ['video_bitrate' => '650k', 'audio_bitrate' => '48k', 'preset' => 'veryslow'],
        ];

        $attempts = min($maxAttempts, count($profiles));
        $lastMessage = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $profile = $profiles[$attempt - 1];
            $tempOutput = $this->buildTempOutputPath($filePath, $attempt);

            $command = sprintf(
                'ffmpeg -y -i %s -c:v libx264 -preset %s -b:v %s -maxrate %s -bufsize %s -c:a aac -b:a %s -movflags +faststart %s 2>&1',
                escapeshellarg($filePath),
                escapeshellarg($profile['preset']),
                escapeshellarg($profile['video_bitrate']),
                escapeshellarg($profile['video_bitrate']),
                escapeshellarg($profile['video_bitrate']),
                escapeshellarg($profile['audio_bitrate']),
                escapeshellarg($tempOutput)
            );

            $output = shell_exec($command);

            if (!is_file($tempOutput)) {
                $lastMessage = 'ffmpeg no generó archivo de salida.';
                if ($output) {
                    $lastMessage .= ' ' . trim($output);
                }
                continue;
            }

            $tempSize = filesize($tempOutput);
            if ($tempSize !== false && $tempSize <= $maxBytes) {
                if (!$this->replaceOriginalFile($tempOutput, $filePath)) {
                    @unlink($tempOutput);
                    return [
                        'success' => false,
                        'compressed' => false,
                        'final_size' => $this->safeFileSize($filePath),
                        'message' => 'No se pudo reemplazar el archivo original con el video comprimido.',
                    ];
                }

                return [
                    'success' => true,
                    'compressed' => true,
                    'final_size' => $this->safeFileSize($filePath),
                    'message' => null,
                ];
            }

            @unlink($tempOutput);
            $lastMessage = 'La compresión no logró reducir el archivo por debajo del límite permitido.';
        }

        return [
            'success' => false,
            'compressed' => false,
            'final_size' => $this->safeFileSize($filePath),
            'message' => $lastMessage ?: 'No se pudo comprimir el video al tamaño máximo permitido.',
        ];
    }

    /**
     * @return array{success: bool, compressed: bool, final_size: int|null, message: string|null}
     */
    protected function compressImage(string $filePath, int $maxBytes, int $maxAttempts): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $qualityMap = [
            'jpg' => [82, 72, 60],
            'jpeg' => [82, 72, 60],
            'png' => [4, 7, 9],
            'webp' => [80, 65, 50],
        ];

        if (!isset($qualityMap[$extension])) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => $this->safeFileSize($filePath),
                'message' => "Formato de imagen no soportado para compresión: {$extension}",
            ];
        }

        $image = $this->loadImageResource($filePath, $extension);
        if (!$image) {
            return [
                'success' => false,
                'compressed' => false,
                'final_size' => $this->safeFileSize($filePath),
                'message' => 'No se pudo cargar la imagen para compresión. Verifica que GD esté habilitado.',
            ];
        }

        $qualities = $qualityMap[$extension];
        $attempts = min($maxAttempts, count($qualities));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $quality = $qualities[$attempt - 1];
            $tempOutput = $this->buildTempOutputPath($filePath, $attempt);

            $saved = $this->saveImageResource($image, $extension, $tempOutput, $quality);
            if (!$saved || !is_file($tempOutput)) {
                @unlink($tempOutput);
                continue;
            }

            $tempSize = filesize($tempOutput);
            if ($tempSize !== false && $tempSize <= $maxBytes) {
                imagedestroy($image);

                if (!$this->replaceOriginalFile($tempOutput, $filePath)) {
                    @unlink($tempOutput);
                    return [
                        'success' => false,
                        'compressed' => false,
                        'final_size' => $this->safeFileSize($filePath),
                        'message' => 'No se pudo reemplazar el archivo original con la imagen comprimida.',
                    ];
                }

                return [
                    'success' => true,
                    'compressed' => true,
                    'final_size' => $this->safeFileSize($filePath),
                    'message' => null,
                ];
            }

            @unlink($tempOutput);
        }

        imagedestroy($image);

        return [
            'success' => false,
            'compressed' => false,
            'final_size' => $this->safeFileSize($filePath),
            'message' => 'La imagen continúa por encima del límite permitido tras 3 intentos de compresión.',
        ];
    }

    protected function buildTempOutputPath(string $filePath, int $attempt): string
    {
        $dir = pathinfo($filePath, PATHINFO_DIRNAME);
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return $dir . DIRECTORY_SEPARATOR . $name . '.cmp' . $attempt . '.' . $ext;
    }

    protected function replaceOriginalFile(string $tempPath, string $finalPath): bool
    {
        if (is_file($finalPath) && !@unlink($finalPath)) {
            return false;
        }

        return @rename($tempPath, $finalPath);
    }

    protected function safeFileSize(string $filePath): ?int
    {
        $size = @filesize($filePath);
        return $size === false ? null : $size;
    }

    /**
     * Comprobar si está el paquete ffmpeg instalado en el servidor
     * @return boolean
     */
    protected function isFfmpegAvailable(): bool
    {
        $output = shell_exec('ffmpeg -version 2>&1');
        return is_string($output) && stripos($output, 'ffmpeg version') !== false;
    }

    protected function loadImageResource(string $filePath, string $extension)
    {
        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filePath) : false,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($filePath) : false,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : false,
            default => false,
        };
    }

    protected function saveImageResource($image, string $extension, string $outputPath, int $quality): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagejpeg') ? (bool) @imagejpeg($image, $outputPath, $quality) : false,
            'png' => function_exists('imagepng') ? (bool) @imagepng($image, $outputPath, $quality) : false,
            'webp' => function_exists('imagewebp') ? (bool) @imagewebp($image, $outputPath, $quality) : false,
            default => false,
        };
    }
}
