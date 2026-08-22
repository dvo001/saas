<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Mail;

use App\Core\Infrastructure\Settings\PlatformSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

final readonly class SystemMailer
{
    public function __construct(private MailerInterface $mailer, private Connection $connection, private PlatformSettings $settings, private string $appSecret) {}

    public function send(string $recipient, string $subject, string $body, string $templateKey, ?int $tenantId = null, ?string $replyTo = null, ?string $attachmentPath = null, ?string $attachmentName = null): string
    {
        $publicId = Uuid::v7()->toRfc4122();
        $this->connection->insert('mail_deliveries', ['tenant_id' => $tenantId, 'public_id' => $publicId, 'template_key' => $templateKey, 'recipient_hash' => hash_hmac('sha256', mb_strtolower(trim($recipient)), $this->appSecret), 'subject' => mb_substr($subject, 0, 255), 'status' => 'sending', 'error_reference' => null, 'sent_at' => null, 'created_at' => gmdate('Y-m-d H:i:s')]);
        try {
            $email = (new Email())->to($recipient)->subject($subject)->text($body);
            $sender = $this->settings->get('mail.system_sender');
            if (is_string($sender) && filter_var($sender, FILTER_VALIDATE_EMAIL)) { $email->from($sender); }
            if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $email->replyTo($replyTo); }
            if ($attachmentPath !== null) { $email->attachFromPath($attachmentPath, $attachmentName); }
            $this->mailer->send($email);
            $this->connection->update('mail_deliveries', ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s')], ['public_id' => $publicId]);
        } catch (\Throwable $exception) {
            $reference = Uuid::v7()->toRfc4122();
            $this->connection->update('mail_deliveries', ['status' => 'failed', 'error_reference' => $reference], ['public_id' => $publicId]);
            throw new \RuntimeException('Mail delivery failed. Reference: '.$reference, 0, $exception);
        }
        return $publicId;
    }
}
