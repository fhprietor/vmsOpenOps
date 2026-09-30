<?php

namespace Modules\VmsOpenOps\Notifications;

use App\Contracts\Notification;
use App\Notifications\Channels\Discord\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Modules\VmsOpenOps\Models\OperationRequest;

class OperationApproved extends Notification implements ShouldQueue
{
    use Queueable;
    
    public function __construct(protected OperationRequest $operation)
    {
    }
    
    public function via($notifiable)
    {
        return ['mail', 'discord_webhook'];
    }
    
    public function toMail($notifiable)
    {
        $status = $this->operation->status == 1 ? 'Approved' : 'Rejected';
        $subject = $this->operation->operation_type == 'jumpseat' 
            ? "Jumpseat Request {$status}" 
            : "Ferry Request {$status}";
        
        $line1 = $this->operation->status == 1
            ? "Your {$this->operation->operation_type} request has been approved and processed."
            : "Your {$this->operation->operation_type} request has been rejected.";
        
        $mail = (new MailMessage)
            ->subject($subject)
            ->line($line1);
        
        if ($this->operation->operation_type == 'jumpseat') {
            $mail->line("You have been moved from {$this->operation->from_airport_id} to {$this->operation->to_airport_id}.")
                 ->line("Distance: " . number_format($this->operation->distance, 2) . " NM")
                 ->line("Cost: " . $this->operation->cost_formatted);
        } else {
            $mail->line("Aircraft {$this->operation->aircraft->registration} has been moved from {$this->operation->from_airport_id} to {$this->operation->to_airport_id}.")
                 ->line("Distance: " . number_format($this->operation->distance, 2) . " NM")
                 ->line("Cost: " . $this->operation->cost_formatted);
        }
        
        if ($this->operation->status == 2 && $this->operation->admin_notes) {
            $mail->line("Admin Notes: {$this->operation->admin_notes}");
        }
        
        return $mail->action('View Request', url('/vmsopenops'));
    }
    
    public function toDiscordChannel($notifiable): DiscordMessage
    {
        $dm = new DiscordMessage();
        $status = $this->operation->status == 1 ? 'Approved' : 'Rejected';
        $color = $this->operation->status == 1 ? 'success' : 'error';
        $title = $this->operation->operation_type == 'jumpseat' 
            ? "Jumpseat {$status}" 
            : "Ferry {$status}";
        
        $fields = [
            'Pilot' => $this->operation->user->ident . ' - ' . $this->operation->user->name_private,
            'Distance' => number_format($this->operation->distance, 2) . ' NM',
            'Cost' => $this->operation->cost_formatted,
        ];
        
        if ($this->operation->operation_type == 'jumpseat') {
            $fields['From → To'] = "{$this->operation->from_airport_id} → {$this->operation->to_airport_id}";
        } else {
            $fields['Aircraft'] = $this->operation->aircraft->registration ?? 'N/A';
            $fields['From → To'] = "{$this->operation->from_airport_id} → {$this->operation->to_airport_id}";
        }
        
        if ($this->operation->admin_notes) {
            $fields['Admin Notes'] = $this->operation->admin_notes;
        }
        
        return $dm->webhook(setting('notifications.discord_private_webhook_url'))
            ->$color()
            ->title($title)
            ->author([
                'name' => $this->operation->user->ident . ' - ' . $this->operation->user->name_private,
                'icon_url' => $this->operation->user->resolveAvatarUrl(),
            ])
            ->fields($fields);
    }
    
    public function toArray($notifiable)
    {
        return [
            'request_id' => $this->operation->id,
            'operation_type' => $this->operation->operation_type,
            'status' => $this->operation->status,
        ];
    }
}