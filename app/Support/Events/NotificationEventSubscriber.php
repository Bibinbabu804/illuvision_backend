<?php

namespace App\Support\Events;
use App\Support\Events\Notifications\BookingApproved;
use App\Support\Events\Notifications\BookingCancelled;
use App\Support\Events\Notifications\BookingCreated;
use App\Support\Events\Notifications\BookingRejected;
use App\Support\Events\Notifications\CommissionGenerated;
use App\Support\Events\Notifications\MediaApproved;
use App\Support\Events\Notifications\MediaRejected;
use App\Support\Events\Notifications\MediaUploaded;
use App\Support\Events\Notifications\PartnerCreated;
use App\Support\Events\Notifications\PartnerApproved;
use App\Support\Events\Notifications\PartnerRejected;
use App\Support\Events\Notifications\PaymentFailed;
use App\Support\Events\Notifications\PaymentReceived;
use App\Support\Events\Notifications\PaymentRefunded;
use App\Support\Events\Notifications\ProfileUpdated;
use App\Support\Events\Notifications\ProductEnquirySubmitted;
use App\Support\Events\Notifications\ReferralUsed;
use App\Support\Events\Notifications\UserRegistered;
use App\Mail\BookingApprovedMail;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingCreatedMail;
use App\Mail\BookingRejectedMail;
use App\Mail\CommissionGeneratedMail;
use App\Mail\MediaApprovedMail;
use App\Mail\MediaRejectedMail;
use App\Mail\PartnerCreatedMail;
use App\Mail\PartnerApprovedMail;
use App\Mail\PartnerRejectedMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\PaymentRefundedMail;
use App\Mail\ReferralUsedMail;
use App\Support\Events\Notifications\ContactEnquirySubmitted;
use App\Support\Events\Notifications\ContactEnquiryAssigned;
use App\Support\Events\Notifications\ContactEnquiryClosed;
use App\Support\Events\Notifications\ScreenProviderCreated;
use App\Support\Events\Notifications\ScreenProviderApproved;
use App\Support\Events\Notifications\ScreenProviderRejected;
use App\Support\Events\Notifications\ScreenProviderPortalInvitation;
use App\Support\Events\Notifications\ScreenProviderEnquirySubmitted;
use App\Support\Events\Notifications\ScreenProviderLeadAssigned;
use App\Support\Events\Notifications\ScreenProviderLeadConverted;
use App\Support\Events\Notifications\ScreenProviderLeadClosed;
use App\Mail\ContactEnquirySubmittedMail;
use App\Mail\ContactEnquiryAutoReplyMail;
use App\Mail\ProductEnquirySubmittedMail;
use App\Mail\ScreenProviderEnquirySubmittedMail;
use App\Mail\ScreenProviderEnquiryAutoReplyMail;
use App\Mail\ScreenProviderApprovedMail;
use App\Mail\ScreenProviderRejectedMail;
use App\Mail\ScreenProviderPortalInvitationMail;
use App\Mail\UserRegisteredMail;
use App\Support\Events\Notifications\RewardsEarned;
use App\Support\Events\Notifications\RewardsRedeemed;
use App\Support\Events\Notifications\RewardsBonusAwarded;
use App\Support\Events\Notifications\RewardsExpiringSoon;
use App\Mail\RewardsEarnedMail;
use App\Mail\RewardsRedeemedMail;
use App\Mail\RewardsBonusAwardedMail;
use App\Mail\RewardsExpiringSoonMail;
use App\Services\NotificationService;
use Illuminate\Events\Dispatcher;

class NotificationEventSubscriber
{
    protected NotificationService $notificationService;

    protected static array $processed = [];

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    protected function deduplicate(string $eventClass, int|string $entityId): bool
    {
        $key = $eventClass . ':' . $entityId;
        if (isset(self::$processed[$key])) {
            return true;
        }
        self::$processed[$key] = false;
        return false;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(BookingCreated::class, [$this, 'handleBookingCreated']);
        $events->listen(BookingApproved::class, [$this, 'handleBookingApproved']);
        $events->listen(BookingRejected::class, [$this, 'handleBookingRejected']);
        $events->listen(BookingCancelled::class, [$this, 'handleBookingCancelled']);
        $events->listen(MediaUploaded::class, [$this, 'handleMediaUploaded']);
        $events->listen(MediaApproved::class, [$this, 'handleMediaApproved']);
        $events->listen(MediaRejected::class, [$this, 'handleMediaRejected']);
        $events->listen(PaymentReceived::class, [$this, 'handlePaymentReceived']);
        $events->listen(PaymentFailed::class, [$this, 'handlePaymentFailed']);
        $events->listen(PaymentRefunded::class, [$this, 'handlePaymentRefunded']);
        $events->listen(ReferralUsed::class, [$this, 'handleReferralUsed']);
        $events->listen(CommissionGenerated::class, [$this, 'handleCommissionGenerated']);
        $events->listen(PartnerCreated::class, [$this, 'handlePartnerCreated']);
        $events->listen(PartnerApproved::class, [$this, 'handlePartnerApproved']);
        $events->listen(PartnerRejected::class, [$this, 'handlePartnerRejected']);
        $events->listen(UserRegistered::class, [$this, 'handleUserRegistered']);
        $events->listen(ProfileUpdated::class, [$this, 'handleProfileUpdated']);
        $events->listen(ProductEnquirySubmitted::class, [$this, 'handleProductEnquirySubmitted']);
        $events->listen(ContactEnquirySubmitted::class, [$this, 'handleContactEnquirySubmitted']);
        $events->listen(ContactEnquiryAssigned::class, [$this, 'handleContactEnquiryAssigned']);
        $events->listen(ContactEnquiryClosed::class, [$this, 'handleContactEnquiryClosed']);
        $events->listen(ScreenProviderCreated::class, [$this, 'handleScreenProviderCreated']);
        $events->listen(ScreenProviderApproved::class, [$this, 'handleScreenProviderApproved']);
        $events->listen(ScreenProviderRejected::class, [$this, 'handleScreenProviderRejected']);
        $events->listen(ScreenProviderPortalInvitation::class, [$this, 'handleScreenProviderPortalInvitation']);
        $events->listen(ScreenProviderEnquirySubmitted::class, [$this, 'handleScreenProviderEnquirySubmitted']);
        $events->listen(ScreenProviderLeadAssigned::class, [$this, 'handleScreenProviderLeadAssigned']);
        $events->listen(ScreenProviderLeadConverted::class, [$this, 'handleScreenProviderLeadConverted']);
        $events->listen(ScreenProviderLeadClosed::class, [$this, 'handleScreenProviderLeadClosed']);
        $events->listen(RewardsEarned::class, [$this, 'handleRewardsEarned']);
        $events->listen(RewardsRedeemed::class, [$this, 'handleRewardsRedeemed']);
        $events->listen(RewardsBonusAwarded::class, [$this, 'handleRewardsBonusAwarded']);
        $events->listen(RewardsExpiringSoon::class, [$this, 'handleRewardsExpiringSoon']);
    }

    public function handleBookingCreated(BookingCreated $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(BookingCreated::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('booking_created', ['admin' => true, 'user' => $user, 'partner' => null], BookingCreatedMail::class, [$booking]);
    }

    public function handleBookingApproved(BookingApproved $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(BookingApproved::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('booking_approved', ['admin' => true, 'user' => $user, 'partner' => null], BookingApprovedMail::class, [$booking]);
    }

    public function handleBookingRejected(BookingRejected $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(BookingRejected::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('booking_rejected', ['admin' => false, 'user' => $user, 'partner' => null], BookingRejectedMail::class, [$booking, $event->reason]);
    }

    public function handleBookingCancelled(BookingCancelled $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(BookingCancelled::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('booking_cancelled', ['admin' => true, 'user' => $user, 'partner' => null], BookingCancelledMail::class, [$booking]);
    }

    public function handleMediaUploaded(MediaUploaded $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(MediaUploaded::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('media_uploaded', ['admin' => true, 'user' => $user, 'partner' => null], BookingCreatedMail::class, [$booking]);
    }

    public function handleMediaApproved(MediaApproved $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(MediaApproved::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('media_approved', ['admin' => false, 'user' => $user, 'partner' => null], MediaApprovedMail::class, [$booking, $event->media]);
    }

    public function handleMediaRejected(MediaRejected $event): void
    {
        $booking = $event->booking;
        if ($this->deduplicate(MediaRejected::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('media_rejected', ['admin' => false, 'user' => $user, 'partner' => null], MediaRejectedMail::class, [$booking, $event->media, $event->reason]);
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $payment = $event->payment;
        $booking = $event->booking;
        if ($this->deduplicate(PaymentReceived::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('payment_received', ['admin' => true, 'user' => $user, 'partner' => null], PaymentReceivedMail::class, [$payment]);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $payment = $event->payment;
        $booking = $event->booking;
        if ($this->deduplicate(PaymentFailed::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('payment_failed', ['admin' => false, 'user' => $user, 'partner' => null], PaymentFailedMail::class, [$payment]);
    }

    public function handlePaymentRefunded(PaymentRefunded $event): void
    {
        $payment = $event->payment;
        $booking = $event->booking;
        if ($this->deduplicate(PaymentRefunded::class, $booking->id)) return;
        $user = $booking->user;
        $this->notificationService->send('payment_refunded', ['admin' => true, 'user' => $user, 'partner' => null], PaymentRefundedMail::class, [$payment, $event->reason]);
    }

    public function handleReferralUsed(ReferralUsed $event): void
    {
        $usage = $event->usage;
        $partner = $usage->partner;
        $booking = $event->booking;
        if ($this->deduplicate(ReferralUsed::class, $usage->id)) return;
        $partnerData = $partner ? ['email' => $partner->email, 'name' => $partner->name] : null;
        $this->notificationService->send('referral_used', ['admin' => true, 'user' => null, 'partner' => $partnerData], ReferralUsedMail::class, [$event->referralCode, $booking]);
    }

    public function handleCommissionGenerated(CommissionGenerated $event): void
    {
        $commission = $event->commission;
        if ($this->deduplicate(CommissionGenerated::class, $commission->id)) return;
        $partner = $commission->partner;
        $partnerData = $partner ? ['email' => $partner->email, 'name' => $partner->name] : null;
        $this->notificationService->send('commission_generated', ['admin' => true, 'user' => null, 'partner' => $partnerData], CommissionGeneratedMail::class, [$commission]);
    }

    public function handlePartnerCreated(PartnerCreated $event): void
    {
        if ($this->deduplicate(PartnerCreated::class, $event->partner->id)) return;
        $this->notificationService->send('partner_created', ['admin' => true, 'user' => null, 'partner' => null], PartnerCreatedMail::class, [$event->partner]);
    }

    public function handlePartnerApproved(PartnerApproved $event): void
    {
        $partner = $event->partner;
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $setupUrl = rtrim($frontendUrl, '/') . '/partner/setup-password?email=' . urlencode($partner->email) . '&token=' . $event->setupToken;
        $partnerData = ['email' => $partner->email, 'name' => $partner->name];
        $this->notificationService->send('partner_approved', ['admin' => false, 'user' => null, 'partner' => $partnerData], PartnerApprovedMail::class, [$partner, $setupUrl]);
    }

    public function handlePartnerRejected(PartnerRejected $event): void
    {
        $partner = $event->partner;
        $partnerData = ['email' => $partner->email, 'name' => $partner->name];
        $this->notificationService->send('partner_rejected', ['admin' => false, 'user' => null, 'partner' => $partnerData], PartnerRejectedMail::class, [$partner, $event->reason]);
    }

    public function handleUserRegistered(UserRegistered $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(UserRegistered::class, $user->id)) return;
        $this->notificationService->send('user_registered', ['admin' => true, 'user' => $user, 'partner' => null], UserRegisteredMail::class, [$user]);
    }

    public function handleProfileUpdated(ProfileUpdated $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(ProfileUpdated::class, $user->id)) return;
        $this->notificationService->send('profile_updated', ['admin' => false, 'user' => $user, 'partner' => null], UserRegisteredMail::class, [$user]);
    }

    public function handleProductEnquirySubmitted(ProductEnquirySubmitted $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ProductEnquirySubmitted::class, $enquiry->id)) return;
        $this->notificationService->send('product_enquiry_submitted', ['admin' => true, 'user' => null, 'partner' => null], ProductEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleContactEnquirySubmitted(ContactEnquirySubmitted $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ContactEnquirySubmitted::class, $enquiry->id)) return;
        $this->notificationService->send('contact_enquiry_submitted', ['admin' => true, 'user' => null, 'partner' => null], ContactEnquirySubmittedMail::class, [$enquiry]);
        $visitor = (object) ['email' => $enquiry->email, 'name' => $enquiry->name];
        $this->notificationService->send('contact_enquiry_auto_reply', ['admin' => false, 'user' => $visitor, 'partner' => null], ContactEnquiryAutoReplyMail::class, [$enquiry]);
    }

    public function handleContactEnquiryAssigned(ContactEnquiryAssigned $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ContactEnquiryAssigned::class, $enquiry->id)) return;
        $this->notificationService->send('contact_enquiry_assigned', ['admin' => true, 'user' => null, 'partner' => null], ContactEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleContactEnquiryClosed(ContactEnquiryClosed $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ContactEnquiryClosed::class, $enquiry->id)) return;
        $this->notificationService->send('contact_enquiry_closed', ['admin' => true, 'user' => null, 'partner' => null], ContactEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleScreenProviderEnquirySubmitted(ScreenProviderEnquirySubmitted $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ScreenProviderEnquirySubmitted::class, $enquiry->id)) return;
        $this->notificationService->send('screen_provider_enquiry_submitted', ['admin' => true, 'user' => null, 'partner' => null], ScreenProviderEnquirySubmittedMail::class, [$enquiry]);
        $visitor = (object) ['email' => $enquiry->email, 'name' => $enquiry->name];
        $this->notificationService->send('screen_provider_enquiry_auto_reply', ['admin' => false, 'user' => $visitor, 'partner' => null], ScreenProviderEnquiryAutoReplyMail::class, [$enquiry]);
    }

    public function handleScreenProviderLeadAssigned(ScreenProviderLeadAssigned $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ScreenProviderLeadAssigned::class, $enquiry->id)) return;
        $this->notificationService->send('screen_provider_lead_assigned', ['admin' => true, 'user' => null, 'partner' => null], ScreenProviderEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleScreenProviderLeadConverted(ScreenProviderLeadConverted $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ScreenProviderLeadConverted::class, $enquiry->id)) return;
        $this->notificationService->send('screen_provider_lead_converted', ['admin' => true, 'user' => null, 'partner' => null], ScreenProviderEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleScreenProviderLeadClosed(ScreenProviderLeadClosed $event): void
    {
        $enquiry = $event->enquiry;
        if ($this->deduplicate(ScreenProviderLeadClosed::class, $enquiry->id)) return;
        $this->notificationService->send('screen_provider_lead_closed', ['admin' => true, 'user' => null, 'partner' => null], ScreenProviderEnquirySubmittedMail::class, [$enquiry]);
    }

    public function handleScreenProviderCreated(ScreenProviderCreated $event): void
    {
        $provider = $event->screenProvider;
        if ($this->deduplicate(ScreenProviderCreated::class, $provider->id)) return;
        $this->notificationService->send('screen_provider_created', ['admin' => true, 'user' => null, 'partner' => null], ScreenProviderApprovedMail::class, [$provider, '']);
    }

    public function handleScreenProviderApproved(ScreenProviderApproved $event): void
    {
        $provider = $event->screenProvider;
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $setupUrl = $event->setupToken
            ? rtrim($frontendUrl, '/') . '/screen-provider/setup-password?email=' . urlencode($provider->email) . '&token=' . $event->setupToken
            : '';
        $providerData = ['email' => $provider->email, 'name' => $provider->provider_name];
        $this->notificationService->send('screen_provider_approved', ['admin' => true, 'user' => null, 'partner' => $providerData], ScreenProviderApprovedMail::class, [$provider, $setupUrl]);
    }

    public function handleScreenProviderRejected(ScreenProviderRejected $event): void
    {
        $provider = $event->screenProvider;
        $providerData = ['email' => $provider->email, 'name' => $provider->provider_name];
        $this->notificationService->send('screen_provider_rejected', ['admin' => true, 'user' => null, 'partner' => $providerData], ScreenProviderRejectedMail::class, [$provider, $event->reason]);
    }

    public function handleScreenProviderPortalInvitation(ScreenProviderPortalInvitation $event): void
    {
        $provider = $event->screenProvider;
        if ($this->deduplicate(ScreenProviderPortalInvitation::class, $provider->id . ':' . $event->setupToken)) return;
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $setupUrl = rtrim($frontendUrl, '/') . '/screen-provider/setup-password?email=' . urlencode($provider->email) . '&token=' . $event->setupToken;
        $providerData = ['email' => $provider->email, 'name' => $provider->provider_name];
        $this->notificationService->send('screen_provider_portal_invitation', ['admin' => false, 'user' => null, 'partner' => $providerData], ScreenProviderPortalInvitationMail::class, [$provider, $setupUrl]);
    }

    public function handleRewardsEarned(RewardsEarned $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(RewardsEarned::class, $user->id . ':' . $event->booking->id)) return;
        $this->notificationService->send('rewards_earned', ['admin' => false, 'user' => $user, 'partner' => null], RewardsEarnedMail::class, [$user, $event->points, $event->booking]);
    }

    public function handleRewardsRedeemed(RewardsRedeemed $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(RewardsRedeemed::class, $user->id . ':' . $event->booking->id)) return;
        $this->notificationService->send('rewards_redeemed', ['admin' => false, 'user' => $user, 'partner' => null], RewardsRedeemedMail::class, [$user, $event->points, $event->booking]);
    }

    public function handleRewardsBonusAwarded(RewardsBonusAwarded $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(RewardsBonusAwarded::class, $user->id . ':' . $event->points)) return;
        $this->notificationService->send('rewards_bonus_awarded', ['admin' => false, 'user' => $user, 'partner' => null], RewardsBonusAwardedMail::class, [$user, $event->points, $event->reason]);
    }

    public function handleRewardsExpiringSoon(RewardsExpiringSoon $event): void
    {
        $user = $event->user;
        if ($this->deduplicate(RewardsExpiringSoon::class, $user->id . ':' . $event->expiryDate)) return;
        $this->notificationService->send('rewards_expiring_soon', ['admin' => false, 'user' => $user, 'partner' => null], RewardsExpiringSoonMail::class, [$user, $event->expiringPoints, $event->expiryDate]);
    }
}
