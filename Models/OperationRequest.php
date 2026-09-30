<?php

namespace Modules\VmsOpenOps\Models;

use App\Contracts\Model;
use App\Models\User;
use App\Models\Airport;
use App\Models\Aircraft;
use App\Models\Subfleet;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class OperationRequest extends Model
{
    protected $table = 'vms_open_ops_requests';
    
    protected $fillable = [
        'operation_type',
        'user_id',
        'from_airport_id',
        'to_airport_id',
        'aircraft_id',
        'subfleet_id',
        'aircraft_distance',
        'distance',
        'cost',
        'reason',
        'type',
        'status',
        'approved_by',
        'admin_notes',
        'approved_at'
    ];
    
    protected $casts = [
        'operation_type' => 'string',
        'type' => 'integer',
        'status' => 'integer',
        'distance' => 'decimal:2',
        'aircraft_distance' => 'decimal:2',
        'cost' => 'integer',
        'approved_at' => 'datetime',
    ];
    
    protected $dates = [
        'approved_at',
        'created_at',
        'updated_at'
    ];
    
    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function fromAirport()
    {
        return $this->belongsTo(Airport::class, 'from_airport_id', 'id');
    }
    
    public function toAirport()
    {
        return $this->belongsTo(Airport::class, 'to_airport_id', 'id');
    }
    
    public function aircraft()
    {
        return $this->belongsTo(Aircraft::class);
    }
    
    public function subfleet()
    {
        return $this->belongsTo(Subfleet::class);
    }
    
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    
    // Accessors
    public function getCostFormattedAttribute()
    {
        return (new Money($this->cost))->money->formatForHumans();
    }
    
    public function getOperationTypeTextAttribute()
    {
        return $this->operation_type == 'jumpseat' ? 'Jumpseat' : 'Ferry';
    }
    
    public function getTypeTextAttribute()
    {
        return $this->type == 0 ? 'Request' : 'Immediate';
    }
    
    public function getStatusTextAttribute()
    {
        $statuses = [
            0 => 'Pending',
            1 => 'Approved',
            2 => 'Rejected'
        ];
        
        return $statuses[$this->status] ?? 'Unknown';
    }
    
    public function getStatusBadgeClassAttribute()
    {
        $classes = [
            0 => 'badge-warning',
            1 => 'badge-success',
            2 => 'badge-danger'
        ];
        
        return $classes[$this->status] ?? 'badge-secondary';
    }
    
    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 0);
    }
    
    public function scopeApproved($query)
    {
        return $query->where('status', 1);
    }
    
    public function scopeRejected($query)
    {
        return $query->where('status', 2);
    }
    
    public function scopeJumpseat($query)
    {
        return $query->where('operation_type', 'jumpseat');
    }
    
    public function scopeFerry($query)
    {
        return $query->where('operation_type', 'ferry');
    }
    
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
    
    /**
     * Check if user has pending request of a specific type
     */
    public static function hasPendingRequest($userId, $operationType = null, $lock = false)
    {
        $query = self::where('user_id', $userId)
            ->where('status', 0);
        
        if ($operationType) {
            $query->where('operation_type', $operationType);
        }
        
        if ($lock) {
            $query->lockForUpdate();
        }
        
        return $query->exists();
    }
    
    /**
     * Get user's pending requests count
     */
    public static function getPendingCount($userId, $operationType = null)
    {
        $query = self::where('user_id', $userId)
            ->where('status', 0);
        
        if ($operationType) {
            $query->where('operation_type', $operationType);
        }
        
        return $query->count();
    }
    
    /**
     * Get user's pending request (if any)
     */
    public static function getPendingRequest($userId, $operationType = null)
    {
        $query = self::where('user_id', $userId)
            ->where('status', 0);
        
        if ($operationType) {
            $query->where('operation_type', $operationType);
        }
        
        return $query->first();
    }

    /**
     * Route notifications for the channel.
     */
    public function routeNotificationFor($channel)
    {
        if ($channel === 'mail') {
            return $this->user->email;
        }
        
        if ($channel === 'discord_webhook') {
            // Usar webhook de staff si está configurado, si no el general
            $staffWebhook = setting('vms_open_ops.discord_staff_webhook');
            if (!empty($staffWebhook)) {
                return $staffWebhook;
            }
            return setting('notifications.discord_private_webhook_url');
        }
        
        return null;
    }
}