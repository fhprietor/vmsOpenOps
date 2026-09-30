<?php

namespace Modules\VmsOpenOps\Notifications;

use App\Contracts\Notification;
use App\Notifications\Channels\Discord\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Modules\VmsOpenOps\Models\OperationRequest;

class OperationRequested extends Notification implements ShouldQueue
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
        $subject = $this->operation->operation_type == 'jumpseat' 
            ? 'New Jumpseat Request' 
            : 'New Ferry Request';
        
        $line1 = $this->operation->operation_type == 'jumpseat'
            ? "A new jumpseat request has been submitted from {$this->operation->from_airport_id} to {$this->operation->to_airport_id}."
            : "A new ferry request has been submitted for {$this->operation->aircraft->registration} from {$this->operation->from_airport_id} to {$this->operation->to_airport_id}.";
        
        return (new MailMessage)
            ->subject($subject)
            ->line($line1)
            ->line("Distance: " . number_format($this->operation->distance, 2) . " NM")
            ->line("Cost: " . $this->operation->cost_formatted)
            ->line("Pilot: {$this->operation->user->ident} - {$this->operation->user->name_private}")
            ->when($this->operation->reason, function ($mail) {
                return $mail->line("Reason: {$this->operation->reason}");
            })
            ->action('View Request', url('/admin/vmsopenops'));
    }
    
public function toDiscordChannel($notifiable): DiscordMessage
{
    $dm = new DiscordMessage();
    
    // Usar webhook de staff si está configurado, si no el general
    $webhookUrl = \App\Models\Setting::where('key', 'vms_open_ops.discord_staff_webhook')->first()->value ?? '';
    if (empty($webhookUrl)) {
        $webhookUrl = setting('notifications.discord_private_webhook_url');
    }

    $title = $this->operation->operation_type == 'jumpseat' 
        ? '✈️ New Jumpseat Request' 
        : '🛩️ New Ferry Request';
    
    $adminUrl = url('/admin/vmsopenops?status=0');
    
    $fields = [
        'Pilot' => $this->operation->user->ident . ' - ' . $this->operation->user->name_private,
        'From' => $this->operation->from_airport_id,
        'To' => $this->operation->to_airport_id,
        'Distance' => number_format($this->operation->distance, 2) . ' NM',
        'Cost' => $this->operation->cost_formatted,
        'Reason' => $this->operation->reason ?? 'Not provided',
        'Link to approve' => $adminUrl,
    ];
    
    if ($this->operation->operation_type == 'ferry') {
        $fields['Aircraft'] = $this->operation->aircraft->registration ?? 'N/A';
        unset($fields['From']);
        $fields = ['Aircraft' => $fields['Aircraft']] + $fields;
    }
    
    return $dm->webhook($webhookUrl)
        ->warning()
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
            'user_id' => $this->operation->user_id,
        ];
    }
}