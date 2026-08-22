<?php

declare(strict_types=1);

namespace App\Core\Application\Document;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class TenantLogoService
{
    public function __construct(private Connection $connection, private PlatformSettings $settings, private AuditLogger $audit, private string $projectDirectory) {}

    public function upload(TenantUser $actor, UploadedFile $file, string $ip): void
    {
        if (!in_array($actor->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) { throw new \DomainException('Nur Owner oder Administratoren dürfen das Vereinslogo ändern.'); }
        if (!$file->isValid() || $file->getSize() === false || $file->getSize() > 2 * 1024 * 1024) { throw new \DomainException('Das Logo muss eine gültige PNG- oder JPEG-Datei mit maximal 2 MB sein.'); }
        $bytes = $file->getContent(); $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) { throw new \DomainException('Als Vereinslogo sind nur PNG und JPEG erlaubt.'); }
        $source = @imagecreatefromstring($bytes); if ($source === false) { throw new \DomainException('Das Bild konnte nicht sicher dekodiert werden.'); }
        $width = imagesx($source); $height = imagesy($source); $maximum = max(100, min(10000, (int) $this->settings->get('uploads.logo_max_pixels', 2400))); $scale = min(1, $maximum / max($width, $height)); $targetWidth = max(1, (int) floor($width * $scale)); $targetHeight = max(1, (int) floor($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight); if ($target === false) { imagedestroy($source); throw new \RuntimeException('Das Logo konnte nicht verarbeitet werden.'); }
        imagealphablending($target, false); imagesavealpha($target, true); $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        if ($transparent === false) { imagedestroy($source); imagedestroy($target); throw new \RuntimeException('Der transparente Bildhintergrund konnte nicht erzeugt werden.'); }
        imagefill($target, 0, 0, $transparent); imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $tenant = $actor->getTenant(); $tenantId = $tenant->getId() ?? throw new \LogicException('Missing tenant id.'); $relative = 'storage/uploads/logos/'.$tenant->getPublicId().'.png'; $absolute = $this->projectDirectory.'/'.$relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) { imagedestroy($source); imagedestroy($target); throw new \RuntimeException('Das Logo-Verzeichnis konnte nicht angelegt werden.'); }
        if (!imagepng($target, $absolute, 8)) { imagedestroy($source); imagedestroy($target); throw new \RuntimeException('Das Logo konnte nicht gespeichert werden.'); }
        chmod($absolute, 0600); imagedestroy($source); imagedestroy($target); $this->connection->update('tenants', ['logo_storage_path' => $relative], ['id' => $tenantId]);
        $this->audit->log('tenant.logo_updated', 'tenant', $tenant->getPublicId(), $tenant, $actor, ['width' => $targetWidth, 'height' => $targetHeight, 'source_mime' => $mime], $ip);
    }
}
