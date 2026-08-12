<?php

declare(strict_types=1);

namespace App\Visit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Object-path rules shared by every VisitPhotoStorage implementation:
 * visits/<reference>/photos/<uuid>.<ext>, extension derived from the
 * validated mime type (the client file name never reaches storage).
 */
final class VisitPhotoPath
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function build(string $visitRef, UploadedFile $file): string
    {
        if (1 !== preg_match('/^[0-9A-Za-z-]+$/', $visitRef)) {
            throw new \RuntimeException('Illegal visit ref.');
        }
        $extension = self::EXTENSIONS[(string) $file->getMimeType()]
            ?? throw new \RuntimeException('Unsupported photo mime type.');

        return 'visits/'.$visitRef.'/photos/'.Uuid::v7()->toRfc4122().'.'.$extension;
    }

    public static function guard(string $path): string
    {
        if (1 !== preg_match('#^visits/[0-9A-Za-z-]+/photos/[0-9a-f-]{36}\.(jpg|png|webp)$#', $path)) {
            throw new \RuntimeException('Illegal visit photo path: '.$path);
        }

        return $path;
    }
}
