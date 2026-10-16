<?php

namespace App\Notifications;

use App\Models\LaboratoryUserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaboratoryUserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly LaboratoryUserInvitation $invitation,
        public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = (string) config('laboratory_users.invitation_frontend_url');
        $separator = str_contains($url, '?') ? '&' : '?';

        return (new MailMessage)
            ->subject('Invitación a '.$this->invitation->laboratory->name)
            ->greeting('Hola, '.$this->invitation->name.'.')
            ->line('Has recibido una invitación para colaborar en '.$this->invitation->laboratory->name.'.')
            ->line('La invitación vence el '.$this->invitation->expires_at->timezone($this->invitation->laboratory->timezone)->format('Y-m-d H:i T').'.')
            ->action('Aceptar invitación', $url.$separator.'token='.urlencode($this->token))
            ->line('Si no esperabas esta invitación, puedes ignorar este mensaje.');
    }
}
