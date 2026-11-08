<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Support\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mail with the link to choose your own password for the admin. */
class AdminInvitation extends Notification
{
    public function __construct(private readonly string $link) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = Setting::get('company', 'name', config('app.name'));

        return (new MailMessage)
            ->subject(__('Your access to the :name admin', ['name' => $name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('You have been given access to the :name admin. Use the button below to choose your own password; then you can log in.', ['name' => $name]))
            ->action(__('Choose password'), $this->link)
            ->line(__('This link is valid for :days days and works once. If you did not expect this email, you can ignore it.', ['days' => Invitation::VALID_DAYS]));
    }
}
