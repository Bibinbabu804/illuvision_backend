<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class FreelanceProject extends Model {
    use SoftDeletes;
    protected $fillable=['client_id','freelancer_profile_id','freelancer_service_id','freelancer_service_package_id','reference','title','description','status','gross_amount','platform_fee','freelancer_amount','delivery_days','revision_limit','revision_count','due_at','completed_at','cancellation_reason','dispute_reason','disputed_at'];
    protected $casts=['gross_amount'=>'decimal:2','platform_fee'=>'decimal:2','freelancer_amount'=>'decimal:2','due_at'=>'datetime','completed_at'=>'datetime','disputed_at'=>'datetime'];
    public function client(): BelongsTo{return $this->belongsTo(User::class,'client_id');}
    public function freelancerProfile(): BelongsTo{return $this->belongsTo(FreelancerProfile::class);}
    public function service(): BelongsTo{return $this->belongsTo(FreelancerService::class,'freelancer_service_id');}
    public function package(): BelongsTo{return $this->belongsTo(FreelancerServicePackage::class,'freelancer_service_package_id');}
    public function proposals(): HasMany{return $this->hasMany(FreelanceProposal::class,'project_id');}
    public function deliverables(): HasMany{return $this->hasMany(FreelanceDeliverable::class,'project_id');}
    public function payments(): HasMany{return $this->hasMany(FreelancePayment::class,'project_id');}
    public function review(): HasOne{return $this->hasOne(FreelanceReview::class,'project_id');}
    public function conversation(): HasOne{return $this->hasOne(FreelanceConversation::class,'project_id');}
    public function files(): HasMany{return $this->hasMany(FreelanceProjectFile::class,'project_id');}
    public static function allowedTransitions(): array
    {
        return [
            'requested' => ['freelancer_reviewing', 'proposal_sent', 'cancelled'],
            'freelancer_reviewing' => ['proposal_sent', 'cancelled'],
            'proposal_sent' => ['payment_pending', 'cancelled'],
            'payment_pending' => ['in_progress', 'cancelled'],
            'in_progress' => ['submitted', 'revision_requested', 'disputed', 'cancelled'],
            'submitted' => ['revision_requested', 'completed', 'disputed'],
            'revision_requested' => ['submitted', 'disputed', 'cancelled'],
            'disputed' => ['cancelled', 'completed'],
            'completed' => [],
            'cancelled' => [],
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedTransitions()[$this->status] ?? [], true);
    }
}
