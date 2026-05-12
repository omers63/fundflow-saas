<?php

namespace App\Notifications;

use App\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EnrollmentSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public Enrollment $enrollment)
    {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New enrollment request',
            'family_id' => $this->enrollment->family_id,
            'enrollment_id' => $this->enrollment->id,
            'applicant_name' => $this->enrollment->applicant_name,
            'status' => $this->enrollment->status,
        ];
    }
}
