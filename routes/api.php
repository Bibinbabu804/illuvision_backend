<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\AdvertisementCategoryController;
use App\Http\Controllers\BlogTagController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductEnquiryController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminProductEnquiryController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\AchievementController;

Route::post('/auth/google', \App\Http\Controllers\Auth\GoogleAuthController::class);

// Forgot & Reset Password
Route::post('/forgot-password', \App\Http\Controllers\Auth\ForgotPasswordController::class);
Route::post('/reset-password', \App\Http\Controllers\Auth\ResetPasswordController::class);
Route::post('/auth/forgot-password-otp', [\App\Http\Controllers\Auth\OtpPasswordResetController::class, 'sendOtp']);
Route::post('/auth/verify-otp', [\App\Http\Controllers\Auth\OtpPasswordResetController::class, 'verifyOtp']);
Route::post('/auth/reset-password-otp', [\App\Http\Controllers\Auth\OtpPasswordResetController::class, 'resetPassword']);

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobCategoryController;
use App\Http\Controllers\JobPostController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\PartnerApprovalController;
use App\Http\Controllers\PartnerPortalController;
use App\Http\Controllers\PartnerClientController;
use App\Http\Controllers\PartnerBookingController;
use App\Http\Controllers\ScreenProviderPortalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PricingPlanController;
use App\Http\Controllers\ReferralAnalyticsController;
use App\Http\Controllers\ReferralCodeController;
use App\Http\Controllers\ScreenController;
use App\Http\Controllers\SuccessStoryController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\DashBoardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminRewardsController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminManualBookingController;
use App\Http\Controllers\QrCodeController;
use Illuminate\Support\Facades\Route;

/* ==========================================================================
   Public Routes (No Authentication Required)
   ========================================================================== */

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Blogs
Route::get("/blogs", [BlogController::class, "index"]);
Route::post("/blogs", [BlogController::class, "store"]);
Route::get("/blogs/featured", [BlogController::class, "featured"]);
Route::get("/blogs/categories", [BlogController::class, "categories"]);
Route::get("/blogs/tags", [BlogController::class, "tags"]);
Route::get("/blogs/{blog}", [BlogController::class, "show"]);
Route::get("/blogs/{blog}/related", [BlogController::class, "related"]);
Route::post("/blogs/{blog}/update-count", [BlogController::class, "updateCount"]);

// Blog categories (public — needed for admin dropdowns and quick-create)
Route::get('/categories', [CategoryController::class, 'index']);

// Jobs
Route::get('/jobs', [JobPostController::class, 'index']);
Route::get('/jobs/{jobPost}', [JobPostController::class, 'show']);
Route::get('/job-categories', [JobCategoryController::class, 'index']);
Route::post('/job-applications', [JobApplicationController::class, 'store']);

// Testimonials
Route::get('/testimonials', [TestimonialController::class, 'index']);

// FAQs
Route::get('/faqs', [FaqController::class, 'index']);

// Achievements
Route::get('/achievements', [AchievementController::class, 'index']);
Route::get('/achievements/{achievement}', [AchievementController::class, 'show']);

// Announcements
Route::get('/announcements', [AnnouncementController::class, 'index']);
Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);

// Success Stories
Route::get('/success-stories', [SuccessStoryController::class, 'index']);

// Gallery
Route::get('/gallery', [GalleryController::class, 'index']);

// Enquiries
Route::post('/enquiries', [EnquiryController::class, 'store']);

Route::get('/services', [\App\Http\Controllers\Api\FreelancingController::class, 'services']);
Route::get('/services/{freelancerService}', [\App\Http\Controllers\Api\FreelancingController::class, 'showService']);

// Freelancing marketplace discovery (public reads).
Route::prefix('freelancing')->group(function () {
    Route::get('/freelancers', [\App\Http\Controllers\Api\FreelancingController::class, 'freelancers']);
    Route::get('/freelancers/{freelancerProfile}', [\App\Http\Controllers\Api\FreelancingController::class, 'showFreelancer']);
    Route::get('/services', [\App\Http\Controllers\Api\FreelancingController::class, 'services']);
    Route::get('/services/{freelancerService}', [\App\Http\Controllers\Api\FreelancingController::class, 'showService']);
    Route::post('/services/{freelancerService}/estimate-price', [\App\Http\Controllers\Api\FreelancingController::class, 'estimatePrice']);
    Route::get('/categories', [\App\Http\Controllers\Api\FreelancingController::class, 'categories']);
    Route::post('/payments/webhook', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'paymentWebhook']);
    Route::post('/assistant', [\App\Http\Controllers\Api\FreelanceAssistantController::class, 'reply'])->middleware('throttle:30,1');
});

// =====================================================================
// SEC-02 — the 19 anonymous /api/v2/* routes.
//
// All 19 were registered here with no auth middleware at all. They are now split by
// evidence, not by URI naming:
//
//   PUBLIC (2)  availability/check, pricing/calculate — verified read-only
//               (no create/save/update/insert/lockScreens anywhere in the call path)
//               and verified to return only customer-facing pricing. A public booking
//               widget cannot ask for a quote or an availability check after login.
//
//   GATED (17)  every state-mutating route, plus every route returning commercially or
//               financially sensitive data. AssetResource exposes
//               illuvision_percentage, screen_provider_percentage and
//               duration_split_overrides — the platform/provider revenue split — and is
//               returned by the asset, coverage and recommendation endpoints.
//               sprint_6_backend_architecture.md documents /api/v2/finance/* as the ADMIN
//               "Finance Overview" dashboard, and batchSettlements can set a settlement
//               to `paid`.
//
// Every permission below already exists in RolesAndPermissionsSeeder. No new
// permission name was introduced, so no migration is required.
// =====================================================================

// Asset Domain v2 APIs
Route::middleware('permission:screens.view')->group(function () {
    Route::get('/v2/assets', [\App\Http\Controllers\Api\AssetController::class, 'index']);
    Route::get('/v2/assets/{id}', [\App\Http\Controllers\Api\AssetController::class, 'show']);
    Route::get('/v2/coverage/cities/{cityId}', [\App\Http\Controllers\Api\CoverageController::class, 'byCity']);
    Route::get('/v2/recommendations', [\App\Http\Controllers\Api\RecommendationEngineController::class, 'index']);
});
Route::post('/v2/assets', [\App\Http\Controllers\Api\AssetController::class, 'store'])
    ->middleware('permission:screens.create');
Route::put('/v2/assets/{id}', [\App\Http\Controllers\Api\AssetController::class, 'update'])
    ->middleware('permission:screens.edit');
Route::delete('/v2/assets/{id}', [\App\Http\Controllers\Api\AssetController::class, 'destroy'])
    ->middleware('permission:screens.delete');
Route::post('/v2/assets/{id}/coverage', [\App\Http\Controllers\Api\CoverageController::class, 'sync'])
    ->middleware('permission:screens.edit');

// Engine v2 APIs
Route::middleware('permission:bookings.view')->group(function () {
    Route::get('/v2/bookings', [\App\Http\Controllers\Api\BookingEngineController::class, 'index']);
    Route::get('/v2/bookings/{id}', [\App\Http\Controllers\Api\BookingEngineController::class, 'show']);
});
// SEC-02 CLASSIFICATION CORRECTION.
//
// `store` is the CUSTOMER self-service booking channel, not a staff capability.
// BookingEngineController::store sets 'user_id' => $request->user()?->id, i.e. the
// booking is scoped to the caller, and BookingChannelsAndRescheduleHardeningTest exercises
// it as a `customer` asserting the daily booking-limit 422. `bookings.create` is a staff
// capability (manager/admin only), so gating this on it returned 403 and BROKE the
// documented customer booking flow - an unintended functional regression, not a security fix.
//
// There is no existing permission meaning "an authenticated customer may book", and
// inventing one is out of scope. So this route is moved from fully-anonymous to
// AUTHENTICATED-ONLY, which is a strict improvement over the original state, and the
// business rules (capacity conflict, daily limit, pricing) still run for the caller.
//
// By contrast index/show return ALL bookings with NO user scoping, so those two stay
// permission-gated on bookings.view: a plain customer must not be able to enumerate
// every other customer's bookings.
Route::post('/v2/bookings', [\App\Http\Controllers\Api\BookingEngineController::class, 'store'])
    ->middleware('auth:sanctum');
Route::post('/v2/bookings/{id}/transition', [\App\Http\Controllers\Api\BookingEngineController::class, 'transition'])
    ->middleware('permission:bookings.edit');
// PUBLIC: read-only quote, no commercially sensitive fields in the response.
Route::post('/v2/pricing/calculate', [\App\Http\Controllers\Api\PricingEngineController::class, 'calculate']);
// PUBLIC: read-only advisory availability check, performs no writes or locks.
Route::post('/v2/availability/check', [\App\Http\Controllers\Api\AvailabilityEngineController::class, 'check']);

// Finance v2 APIs.
//
// VOCABULARY GAP (controlled debt, recorded in phase-0.21-authorization-matrix.json):
// these endpoints are settlement/revenue oriented, but the permission vocabulary seeded
// by RolesAndPermissionsSeeder has NO `settlements.*` section. Inventing one would be new
// RBAC, which is explicitly out of scope, so the existing admin-only `payments.*`
// permissions are reused instead.
//
//   payments.view    -> admin + super-admin only (manager/staff do NOT hold it), which
//                       matches sprint_6_backend_architecture.md describing these as the
//                       ADMIN "Finance Overview" dashboard. `reports.view` was rejected
//                       because manager and staff hold it, which would over-expose gross
//                       revenue, platform commission and refunds to read-only roles.
//   payments.approve -> the batch endpoint can transition a settlement to `paid`, i.e. it
//                       moves money, so it requires approve rather than view.
Route::middleware('permission:payments.view')->group(function () {
    Route::get('/v2/finance/summary', [\App\Http\Controllers\Api\FinanceController::class, 'summary']);
    Route::get('/v2/finance/payments', [\App\Http\Controllers\Api\FinanceController::class, 'payments']);
    Route::get('/v2/finance/settlements', [\App\Http\Controllers\Api\FinanceController::class, 'settlements']);
    Route::get('/v2/finance/ledger', [\App\Http\Controllers\Api\FinanceController::class, 'ledger']);
});
Route::post('/v2/finance/settlements/batch', [\App\Http\Controllers\Api\FinanceController::class, 'batchSettlements'])
    ->middleware('permission:payments.approve');


// Locations / Screens (public dropdown data)
Route::get('/countries', [ScreenController::class, 'countries']);
Route::get('/countries/{country}/states', [ScreenController::class, 'statesByCountry']);
Route::get('/states/{state}/cities', [ScreenController::class, 'citiesByState']);
Route::get('/countries/{country}/cities', [ScreenController::class, 'citiesByCountry']);
Route::get('/pricing-plans', [PricingPlanController::class, 'index']);
Route::get('/pricing-plans/{pricingPlan}', [PricingPlanController::class, 'show']);
Route::get('/screens', [ScreenController::class, 'index']);
Route::get('/screens/map', [ScreenController::class, 'mapIndex']);
Route::get('/screens/{screen}', [ScreenController::class, 'show']);
Route::get('/screen-types', [ScreenController::class, 'screenTypes']);
Route::get('/screen-categories', [\App\Http\Controllers\ScreenCategoryController::class, 'index']);
Route::post('/screen-categories', [\App\Http\Controllers\ScreenCategoryController::class, 'store']);
Route::post('/admin/screen-categories', [\App\Http\Controllers\ScreenCategoryController::class, 'store']);

// Asset Excel Import
Route::get('/admin/assets/import/template', [\App\Http\Controllers\AssetImportController::class, 'downloadTemplate']);
Route::post('/admin/assets/import/validate', [\App\Http\Controllers\AssetImportController::class, 'validateImport']);
Route::post('/admin/assets/import/confirm', [\App\Http\Controllers\AssetImportController::class, 'confirmImport']);

// Pricing Preview
Route::post('/pricing-plans/preview', [PricingPlanController::class, 'preview']);
Route::get('/pricing-plans/{pricingPlan}/preview', [PricingPlanController::class, 'preview']);

// Products
Route::get('/products', [ProductController::class, 'index']);
Route::post('/product-enquiries', [ProductEnquiryController::class, 'store']);

// Campaign Pricing
Route::post('/campaigns/calculate', [BookingController::class, 'calculateCampaignPricing']);
Route::post('/campaigns/validate', [BookingController::class, 'validateCampaignPricing']);
Route::post('/referral-codes/validate', [ReferralCodeController::class, 'validate']);

// Budget Planner Recommendation Engine (BP3) — throttled (BP6 P0-2).
Route::post('/recommendations/calculate', [\App\Http\Controllers\Api\BudgetRecommendationController::class, 'calculate'])
    ->middleware('throttle:recommendations');

// Public Booking Configuration (no auth)
Route::get('/settings/public-booking-config', [SettingsController::class, 'publicBookingConfig']);
Route::get('/advertisement-categories', [AdvertisementCategoryController::class, 'index']);

// Partner Applications (Public - from website)
Route::post('partner-applications', [PartnerApprovalController::class, 'apply']);

// Partner password setup (no auth required - called from email link)
Route::post('partner/setup-password', [PartnerPortalController::class, 'setupPassword']);
Route::post('screen-provider/setup-password', [ScreenProviderPortalController::class, 'setupPassword']);

/* ==========================================================================
   Authenticated Routes (Sanctum)
   ========================================================================== */

Route::middleware('auth:sanctum')->group(function () {
    // Freelancing foundation: freelancer profile, services and portfolio.
    Route::prefix('freelancing')->group(function () {
        Route::get('/projects', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'projects']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'dashboard']);
        Route::get('/notifications', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'notifications']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'markAllNotificationsRead']);
        Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'markNotificationRead']);
        Route::get('/reviews', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'reviews']);
        Route::get('/projects/{project}', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'showProject']);
        Route::post('/projects', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'createProject']);
        Route::post('/projects/{project}/proposals', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'sendProposal']);
        Route::post('/proposals/{proposal}/accept', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'acceptProposal']);
        Route::get('/conversations', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'conversations']);
        Route::get('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'messages']);
        Route::post('/conversations/{conversation}/read', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'markConversationRead']);
        Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'sendMessage']);
        Route::post('/projects/{project}/deliverables', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'submitDeliverable']);
        Route::post('/projects/{project}/milestones/{milestone}/deliverables', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'submitMilestoneDeliverable']);
        Route::post('/projects/{project}/milestones/{milestone}/approve', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'approveMilestone']);
        Route::post('/projects/{project}/milestones/{milestone}/revisions', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'requestMilestoneRevision']);
        Route::post('/projects/{project}/revisions', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'requestRevision']);
        Route::post('/projects/{project}/files', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'uploadProjectFile']);
        Route::get('/projects/{project}/files', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'projectFiles']);
        Route::get('/projects/{project}/deliverables/{deliverable}/comments', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'deliverableComments']);
        Route::post('/projects/{project}/deliverables/{deliverable}/comments', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'addDeliverableComment']);
        Route::post('/projects/{project}/cancel', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'cancelProject']);
        Route::post('/projects/{project}/dispute', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'disputeProject']);
        Route::post('/projects/{project}/approve', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'approveProject']);
        Route::post('/projects/{project}/payments/order', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'createPaymentOrder']);
        Route::post('/payments/{payment}/verify', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'verifyPayment']);
        Route::post('/projects/{project}/reviews', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'createReview']);
        Route::post('/reviews/{review}/reply', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'replyToReview']);
        Route::get('/wallet', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'wallet']);
        Route::match(['post', 'put'], '/wallet/payout-settings', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'updatePayoutSettings']);
        Route::post('/withdrawals', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'requestWithdrawal']);
        Route::get('/withdrawals', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'withdrawals']);
        Route::get('/proposals', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'proposals']);
        Route::middleware('permission:payments.view')->get('/admin/withdrawals', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'adminWithdrawals']);
        Route::middleware('permission:payments.edit')->post('/admin/withdrawals/{withdrawal}/process', [\App\Http\Controllers\Api\FreelanceWorkflowController::class, 'processWithdrawal']);
        Route::get('/me', [\App\Http\Controllers\Api\FreelancingController::class, 'me']);
        Route::put('/me', [\App\Http\Controllers\Api\FreelancingController::class, 'updateProfile']);
        Route::put('/me/skills', [\App\Http\Controllers\Api\FreelancingController::class, 'updateSkills']);
        Route::post('/me/category-requests', [\App\Http\Controllers\Api\FreelancingController::class, 'requestCategory']);
        Route::get('/me/category-requests', [\App\Http\Controllers\Api\FreelancingController::class, 'myCategoryRequests']);
        Route::post('/me/category-requests/{categoryRequest}/approve', [\App\Http\Controllers\Api\FreelancingController::class, 'approveMyCategoryRequest']);
        Route::post('/category-requests', [\App\Http\Controllers\Api\FreelancingController::class, 'requestCategory']);
        Route::get('/category-requests', [\App\Http\Controllers\Api\FreelancingController::class, 'myCategoryRequests']);
        Route::post('/category-requests/{categoryRequest}/approve', [\App\Http\Controllers\Api\FreelancingController::class, 'approveMyCategoryRequest']);
        Route::get('/me/availability', [\App\Http\Controllers\Api\FreelancingController::class, 'getUnavailabilities']);
        Route::post('/me/availability', [\App\Http\Controllers\Api\FreelancingController::class, 'storeUnavailability']);
        Route::delete('/me/availability/{unavailability}', [\App\Http\Controllers\Api\FreelancingController::class, 'deleteUnavailability']);
        Route::get('/me/profile-media', [\App\Http\Controllers\Api\FreelancingController::class, 'getProfileMedia']);
        Route::post('/me/profile-media', [\App\Http\Controllers\Api\FreelancingController::class, 'storeProfileMedia']);
        Route::post('/me/profile-media/{media}/primary', [\App\Http\Controllers\Api\FreelancingController::class, 'setPrimaryProfileMedia']);
        Route::delete('/me/profile-media/{media}', [\App\Http\Controllers\Api\FreelancingController::class, 'deleteProfileMedia']);
        Route::post('/me/profile-media/reorder', [\App\Http\Controllers\Api\FreelancingController::class, 'reorderProfileMedia']);
        Route::post('/me/services', [\App\Http\Controllers\Api\FreelancingController::class, 'storeService']);
        Route::match(['put', 'patch'], '/me/services/{freelancerService}', [\App\Http\Controllers\Api\FreelancingController::class, 'updateService']);
        Route::post('/me/services/{freelancerService}/pause', [\App\Http\Controllers\Api\FreelancingController::class, 'pauseService']);
        Route::post('/me/services/{freelancerService}/resume', [\App\Http\Controllers\Api\FreelancingController::class, 'resumeService']);
        Route::post('/me/services/{freelancerService}/video', [\App\Http\Controllers\Api\FreelancingController::class, 'uploadServiceVideo']);
        Route::delete('/me/services/{freelancerService}/video', [\App\Http\Controllers\Api\FreelancingController::class, 'deleteServiceVideo']);
        Route::post('/services/{freelancerService}/video', [\App\Http\Controllers\Api\FreelancingController::class, 'uploadServiceVideo']);
        Route::delete('/services/{freelancerService}/video', [\App\Http\Controllers\Api\FreelancingController::class, 'deleteServiceVideo']);
        Route::delete('/me/services/{freelancerService}', [\App\Http\Controllers\Api\FreelancingController::class, 'destroyService']);
        Route::post('/me/portfolio', [\App\Http\Controllers\Api\FreelancingController::class, 'storePortfolio']);
        Route::match(['put', 'patch'], '/me/portfolio/{portfolioItem}', [\App\Http\Controllers\Api\FreelancingController::class, 'updatePortfolio']);
        Route::post('/me/portfolio/{portfolioItem}/approve', [\App\Http\Controllers\Api\FreelancingController::class, 'approveMyPortfolio']);
        Route::delete('/me/portfolio/{portfolioItem}', [\App\Http\Controllers\Api\FreelancingController::class, 'destroyPortfolio']);
        Route::post('/me/portfolio/{portfolioItem}/media', [\App\Http\Controllers\Api\FreelancingController::class, 'storePortfolioMedia']);
        Route::post('/me/portfolio/{portfolioItem}/media/{portfolioMedia}/cover', [\App\Http\Controllers\Api\FreelancingController::class, 'setPortfolioCover']);
        Route::post('/me/portfolio/{portfolioItem}/media/reorder', [\App\Http\Controllers\Api\FreelancingController::class, 'reorderPortfolioMedia']);
        Route::delete('/me/portfolio/{portfolioItem}/media/{portfolioMedia}', [\App\Http\Controllers\Api\FreelancingController::class, 'destroyPortfolioMedia']);

        // Admin Freelance Moderation Endpoints
        Route::prefix('admin')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'overview']);
            Route::get('/categories', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'categories']);
            Route::post('/categories', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'storeCategory']);
            Route::put('/categories/{category}', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'updateCategory']);
            Route::delete('/categories/{category}', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'deleteCategory']);
            Route::get('/category-requests', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'categoryRequests']);
            Route::post('/category-requests/{categoryRequest}/approve', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'approveCategoryRequest']);
            Route::post('/category-requests/{categoryRequest}/reject', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'rejectCategoryRequest']);
            Route::get('/services', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'pendingServices']);
            Route::post('/services/{service}/moderate', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'moderateService']);
            Route::get('/portfolios', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'pendingPortfolios']);
            Route::post('/portfolios/{portfolio}/moderate', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'moderatePortfolio']);
            Route::get('/moderation-queue', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'moderationQueue']);
            Route::post('/moderation-queue/{record}/resolve', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'resolveModerationRecord']);
            Route::get('/milestones', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'milestonesOversight']);
            Route::get('/wallet-audit', [\App\Http\Controllers\Api\AdminFreelanceModerationController::class, 'walletAudit']);
        });
    });
    // Auth / Profile
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/profile/dashboard', [ProfileController::class, 'dashboard']);
    Route::get('/profile/campaigns', [ProfileController::class, 'campaigns']);
    Route::get('/profile/history', [ProfileController::class, 'history']);
    Route::get('/profile/campaigns/{id}', [ProfileController::class, 'show']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::get('/profile/activity', [ProfileController::class, 'activity']);

    // Customer Ad Credits
    Route::get('/profile/rewards', [ProfileController::class, 'rewards']);
    Route::get('/profile/rewards/transactions', [ProfileController::class, 'rewardTransactions']);

    // Bookings -- Campaign flow
    Route::post('/bookings/campaign', [BookingController::class, 'storeCampaign']);
    Route::post('/bookings/preview', [BookingController::class, 'preview']);
    Route::get('/bookings/{booking}/campaign', [BookingController::class, 'campaignDetails']);
    Route::post('/bookings/{booking}/media', [BookingController::class, 'uploadMedia']);
    Route::delete('/bookings/{booking}/media/{media}', [BookingController::class, 'deleteMedia']);
    Route::get('/my-bookings', [BookingController::class, 'myBookings']);

    // Bookings -- Existing
    Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
    Route::get('/bookings/occupancy', [BookingController::class, 'occupancy']);
    Route::get('/bookings/kpis', [BookingController::class, 'kpis']);
    Route::post('/bookings/check-conflicts', [BookingController::class, 'checkConflicts']);
    // SEC-01: 'update' is deliberately NOT included here.
    //
    // `apiResource(...)->only(['index','show','store','update','destroy'])` registered
    // `PUT|PATCH /api/bookings/{booking}` with only ['api','auth:sanctum']. Laravel keeps
    // routes in a per-method lookup keyed by domain+URI, so the later explicit
    // `Route::put(...)` overrode the PUT half only, and PATCH remained authenticated
    // but unpermissioned — letting any logged-in user overwrite an arbitrary booking's
    // `status` and `price`. The update route is now registered once, further down, with
    // `Route::match(['put','patch'])` inside the permission group, so both verbs are
    // gated identically and no unpermissioned entry exists to shadow.
    Route::apiResource('bookings', BookingController::class)->only([
        'index', 'show', 'store', 'destroy',
    ]);

    // Payments -- Razorpay
    Route::post('/payments/razorpay/create-order', [PaymentController::class, 'createOrder']);
    Route::post('/payments/razorpay/verify', [PaymentController::class, 'verify']);
    Route::get('/bookings/{bookingId}/payment-status', [PaymentController::class, 'paymentStatus']);

    // Payments -- Admin Direct Verification Queue
    Route::get('/admin/payments/direct-verification-queue', [\App\Http\Controllers\AdminPaymentController::class, 'directPayments']);
    Route::post('/admin/payments/{id}/approve-direct', [\App\Http\Controllers\AdminPaymentController::class, 'approveDirectPayment']);
    Route::post('/admin/payments/{id}/reject-direct', [\App\Http\Controllers\AdminPaymentController::class, 'rejectDirectPayment']);

    // Settings (all require settings.view permission for defense in depth)
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::get('/settings/general', [SettingsController::class, 'getGeneral']);
        Route::get('/settings/booking', [SettingsController::class, 'getBooking']);
        Route::get('/settings/notifications', [SettingsController::class, 'getNotifications']);
        Route::get('/settings/media', [SettingsController::class, 'getMedia']);
        Route::get('/settings/pricing', [SettingsController::class, 'getPricing']);
        Route::get('/settings/referral', [SettingsController::class, 'getReferral']);
        Route::get('/settings/first-booking-discount', [SettingsController::class, 'getFirstBookingDiscount']);
        Route::get('/settings/loyalty', [SettingsController::class, 'getLoyalty']);
    });

    // Settings write operations (require settings.edit permission)
    Route::middleware('permission:settings.edit')->group(function () {
        Route::post('/settings', [SettingsController::class, 'store']);
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral']);
        Route::put('/settings/booking', [SettingsController::class, 'updateBooking']);
        Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications']);
        Route::put('/settings/media', [SettingsController::class, 'updateMedia']);
        Route::put('/settings/pricing', [SettingsController::class, 'updatePricing']);
        Route::put('/settings/referral', [SettingsController::class, 'updateReferral']);
        Route::put('/settings/first-booking-discount', [SettingsController::class, 'updateFirstBookingDiscount']);
        Route::put('/settings/loyalty', [SettingsController::class, 'updateLoyalty']);
    });

    // ===== Admin User Management =====
    Route::middleware('permission:user_management.view')->group(function () {
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('permission:user_management.create');
        Route::get('/admin/users/{user}', [AdminUserController::class, 'show']);
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('permission:user_management.edit');
        Route::post('/admin/users/{user}/status', [AdminUserController::class, 'updateStatus'])->middleware('permission:user_management.edit');
        Route::post('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('permission:user_management.edit');
        Route::get('/admin/users/{user}/activity', [AdminUserController::class, 'activity']);
    });

    // ===== Admin Rewards Management =====
    Route::middleware('permission:rewards.view')->group(function () {
        Route::get('/admin/rewards', [AdminRewardsController::class, 'index']);
        Route::get('/admin/rewards/{id}', [AdminRewardsController::class, 'show']);
        Route::post('/admin/rewards/adjust', [AdminRewardsController::class, 'adjust'])->middleware('permission:rewards.adjust');
    });

    // SEC-03 - Roles & Permissions.
    // These were registered with only ['api','auth:sanctum'], so ANY authenticated user
    // could list, create, edit, delete and clone roles and rewrite a role's permission
    // set - a direct privilege-escalation path.
    //
    // Reuse the EXISTING user_management.* vocabulary (no new roles.* permission was
    // invented, so no migration is required). This mirrors AdminUserController::update,
    // which already changes a user's role behind user_management.edit.
    Route::middleware('permission:user_management.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('permissions/keys', [PermissionController::class, 'listKeys']);
    });
    Route::middleware('permission:user_management.edit')->group(function () {
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::patch('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        Route::post('roles/{role}/clone', [RoleController::class, 'clone']);
        Route::put('roles/{role}/permissions', [PermissionController::class, 'updateRolePermissions']);
    });

    /* =====================================================================
       PARTNER MANAGEMENT (Admin)
       ===================================================================== */

    // SEC-03 - Partner administration.
    // All partner routes were registered with only ['api','auth:sanctum'], so any
    // authenticated user could create, approve, reject, re-status or delete partners
    // and read partner commissions - a revenue-relevant administration surface.
    //
    // Reuse the EXISTING partners.* vocabulary, which already defines
    // view/create/edit/delete/approve/export. partners.view is already granted to
    // manager and staff, so read access is preserved for the roles that had it.
    Route::middleware('permission:partners.view')->group(function () {
        Route::get('partners/kpis', [PartnerController::class, 'kpis']);
        Route::get('partners/all', [PartnerController::class, 'allPartners']);
        Route::get('partners', [PartnerController::class, 'index']);
        Route::get('partners/{partner}', [PartnerController::class, 'show']);
        Route::get('partners/{partner}/timeline', [PartnerController::class, 'timeline']);
        Route::get('partners/{partner}/bookings', [PartnerController::class, 'bookings']);
        Route::get('partners/{partner}/commissions', [PartnerController::class, 'commissions']);
    });
    Route::middleware('permission:partners.create')->group(function () {
        Route::post('partners', [PartnerController::class, 'store']);
        Route::post('partners/create-with-onboarding', [PartnerController::class, 'createWithOnboarding']);
    });
    Route::middleware('permission:partners.edit')->group(function () {
        Route::put('partners/{partner}', [PartnerController::class, 'update']);
        Route::patch('partners/{partner}/status', [PartnerController::class, 'updateStatus']);
        Route::post('partners/{partner}/mark-under-review', [PartnerApprovalController::class, 'markUnderReview']);
    });
    Route::middleware('permission:partners.delete')->group(function () {
        Route::delete('partners/{partner}', [PartnerController::class, 'destroy']);
    });
    // Approval decisions are deliberately gated on partners.approve, NOT partners.edit,
    // so a role that may edit partner details still cannot approve a partner.
    Route::middleware('permission:partners.approve')->group(function () {
        Route::post('partners/{partner}/approve', [PartnerApprovalController::class, 'approve']);
        Route::post('partners/{partner}/reject', [PartnerApprovalController::class, 'reject']);
    });

    // Partner Applications (Admin review queue)
    Route::get('partner-applications', [PartnerApplicationController::class, 'index']);
    Route::get('partner-applications/stats', [PartnerApplicationController::class, 'stats']);
    Route::get('partner-applications/cities', [PartnerApplicationController::class, 'cities']);
    Route::get('partner-applications/{partner}', [PartnerApplicationController::class, 'show']);
    Route::post('partner-applications/{partner}/notes', [PartnerApplicationController::class, 'addNote']);

    // Commission Management
    Route::get('referral-analytics/overview', [ReferralAnalyticsController::class, 'overview']);
    Route::get('referral-analytics/commission-summary', [ReferralAnalyticsController::class, 'commissionSummary']);
    Route::get('referral-analytics/commissions', [ReferralAnalyticsController::class, 'commissions']);
    Route::get('referral-analytics/commissions/{id}', [ReferralAnalyticsController::class, 'commissionDetail']);
    Route::post('referral-analytics/commissions/{id}/approve', [ReferralAnalyticsController::class, 'approve']);
    Route::post('referral-analytics/commissions/{id}/mark-paid', [ReferralAnalyticsController::class, 'markPaid']);
    Route::post('referral-analytics/commissions/{id}/mark-cancelled', [ReferralAnalyticsController::class, 'markCancelled']);
    Route::get('referral-analytics/top-partners', [ReferralAnalyticsController::class, 'topPartners']);
    Route::get('referral-analytics/top-codes', [ReferralAnalyticsController::class, 'topCodes']);

    // Referral Codes (management)
    Route::apiResource('referral-codes', ReferralCodeController::class)->only([
        'index', 'store', 'show', 'update', 'destroy',
    ]);

    // Partner Portal (for approved partners)
    Route::prefix('partner')->group(function () {
        Route::get('dashboard', [PartnerPortalController::class, 'dashboard']);
        Route::get('referral-code', [PartnerPortalController::class, 'referralCode']);
        Route::get('bookings', [PartnerPortalController::class, 'bookings']);
        Route::get('commissions', [PartnerPortalController::class, 'commissions']);
        Route::put('profile', [PartnerPortalController::class, 'updateProfile']);

        // Client Management (My Clients)
        Route::get('clients', [PartnerClientController::class, 'index']);
        Route::post('clients', [PartnerClientController::class, 'store']);
        Route::get('clients/search', [PartnerClientController::class, 'search']);
        Route::get('clients/{id}', [PartnerClientController::class, 'show']);
        Route::put('clients/{id}', [PartnerClientController::class, 'update']);

        // Delegated Booking & Campaign Lifecycle Management
        Route::get('campaigns', [PartnerBookingController::class, 'index']);
        Route::post('bookings/calculate', [PartnerBookingController::class, 'calculate']);
        Route::post('bookings', [PartnerBookingController::class, 'store']);
        Route::get('bookings/{booking}', [PartnerBookingController::class, 'show']);
        Route::post('bookings/{booking}/media', [PartnerBookingController::class, 'uploadMedia']);
        Route::delete('bookings/{booking}/media/{media}', [PartnerBookingController::class, 'deleteMedia']);
        // Partner Reports & Export
        Route::get('reports', [PartnerPortalController::class, 'reports']);
        Route::get('reports/export', [PartnerPortalController::class, 'exportReport']);
    });

    // Screen Provider Portal (for approved screen providers)
    Route::prefix('screen-provider')->group(function () {
        Route::get('dashboard', [ScreenProviderPortalController::class, 'dashboard'])->middleware('permission:screen_provider.dashboard');
        Route::get('screens', [ScreenProviderPortalController::class, 'screens'])->middleware('permission:screen_provider.screens');
        Route::post('screens', [ScreenProviderPortalController::class, 'storeScreen'])->middleware('permission:screen_provider.screens');
        Route::get('screens/{screen}', [ScreenProviderPortalController::class, 'screenDetails'])->middleware('permission:screen_provider.screens');
        Route::put('screens/{screen}', [ScreenProviderPortalController::class, 'updateScreen'])->middleware('permission:screen_provider.screens');
        Route::get('campaigns', [ScreenProviderPortalController::class, 'campaigns'])->middleware('permission:screen_provider.campaigns');
        Route::get('campaigns/{id}', [ScreenProviderPortalController::class, 'campaignDetails'])->middleware('permission:screen_provider.campaigns');
        Route::get('revenue', [ScreenProviderPortalController::class, 'revenue'])->middleware('permission:screen_provider.revenue');
        Route::get('settlements', [ScreenProviderPortalController::class, 'settlements'])->middleware('permission:screen_provider.settlements');
        Route::get('notifications', [ScreenProviderPortalController::class, 'notifications']);
        Route::post('enquiries', [ScreenProviderPortalController::class, 'submitEnquiry'])->middleware('permission:screen_provider.support');
        Route::get('enquiries', [ScreenProviderPortalController::class, 'enquiryHistory'])->middleware('permission:screen_provider.support');
        Route::put('profile', [ScreenProviderPortalController::class, 'updateProfile'])->middleware('permission:screen_provider.profile');
    });
    // ===== Content Management =====
    Route::middleware('permission:blogs.view')->group(function () {
        Route::put('blogs/{blog}', [BlogController::class, 'update'])->middleware('permission:blogs.edit');
        Route::apiResource('categories', CategoryController::class)->except('index');
        Route::apiResource('advertisement-categories', AdvertisementCategoryController::class)->except('index');
        Route::apiResource('blog-tags', BlogTagController::class)->only([
            'index', 'store', 'update', 'destroy',
        ]);
        Route::apiResource('authors', AuthorController::class);
        Route::apiResource('achievements', AchievementController::class)->except(['index', 'show']);
        Route::apiResource('announcements', AnnouncementController::class)->except(['index', 'show']);
        Route::apiResource('success-stories', SuccessStoryController::class);
    });

    // Testimonials
    Route::middleware('permission:testimonials.view')->group(function () {
        Route::post('testimonials', [TestimonialController::class, 'store'])->middleware('permission:testimonials.create');
        Route::get('testimonials/{testimonial}', [TestimonialController::class, 'show']);
        Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->middleware('permission:testimonials.edit');
        Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->middleware('permission:testimonials.delete');
    });

    // FAQs
    Route::middleware('permission:faqs.view')->group(function () {
        Route::apiResource('faqs', FaqController::class)->except(['index']);
    });

    // Job Management
    Route::middleware('permission:jobs.view')->group(function () {
        Route::post('/jobs', [JobPostController::class, 'store'])->middleware('permission:jobs.create');
        Route::put('/jobs/{jobPost}', [JobPostController::class, 'update'])->middleware('permission:jobs.edit');
        Route::delete('/jobs/{jobPost}', [JobPostController::class, 'destroy'])->middleware('permission:jobs.delete');
        Route::get('/job-applications', [JobApplicationController::class, 'index']);
        Route::get('/job-applications/{jobApplication}', [JobApplicationController::class, 'show']);
        Route::delete('/job-applications/{jobApplication}', [JobApplicationController::class, 'destroy'])->middleware('permission:jobs.delete');
    });

    // Products & Product Enquiries
    Route::middleware('permission:products.view')->group(function () {
        Route::get('/admin/products', [AdminProductController::class, 'index']);
        Route::post('/admin/products', [AdminProductController::class, 'store'])->middleware('permission:products.create');
        Route::get('/admin/products/{product}', [AdminProductController::class, 'show']);
        Route::put('/admin/products/{product}', [AdminProductController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('/admin/products/{product}', [AdminProductController::class, 'destroy'])->middleware('permission:products.delete');

        Route::get('/admin/product-enquiries', [AdminProductEnquiryController::class, 'index']);
        Route::get('/admin/product-enquiries/{id}', [AdminProductEnquiryController::class, 'show']);
        Route::post('/admin/product-enquiries/{id}', [AdminProductEnquiryController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('/admin/product-enquiries/{id}', [AdminProductEnquiryController::class, 'destroy'])->middleware('permission:products.delete');
    });

    // Contact Enquiries Management
    Route::middleware('permission:contacts.view')->group(function () {
        Route::get('/admin/contact-enquiries', [App\Http\Controllers\AdminContactEnquiryController::class, 'index']);
        Route::get('/admin/contact-enquiries/{contactEnquiry}', [App\Http\Controllers\AdminContactEnquiryController::class, 'show']);
        Route::put('/admin/contact-enquiries/{contactEnquiry}', [App\Http\Controllers\AdminContactEnquiryController::class, 'update'])->middleware('permission:contacts.edit');
        Route::post('/admin/contact-enquiries/{contactEnquiry}/assign', [App\Http\Controllers\AdminContactEnquiryController::class, 'assign'])->middleware('permission:contacts.edit');
        Route::post('/admin/contact-enquiries/{contactEnquiry}/status', [App\Http\Controllers\AdminContactEnquiryController::class, 'status'])->middleware('permission:contacts.edit');
        Route::post('/admin/contact-enquiries/{contactEnquiry}/notes', [App\Http\Controllers\AdminContactEnquiryController::class, 'notes'])->middleware('permission:contacts.edit');

        Route::get('/enquiries', [EnquiryController::class, 'index']);
        Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show']);
        Route::delete('/enquiries/{enquiry}', [EnquiryController::class, 'destroy'])->middleware('permission:contacts.delete');
    });

    // Screen Provider Enquiries Management
    Route::middleware('permission:screen_providers.view')->group(function () {
        Route::get("/admin/screen-provider-enquiries", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "index"]);
        Route::get("/admin/screen-provider-enquiries/{screenProviderEnquiry}", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "show"]);
        Route::put("/admin/screen-provider-enquiries/{screenProviderEnquiry}", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "update"])->middleware('permission:screen_providers.edit');
        Route::post("/admin/screen-provider-enquiries/{screenProviderEnquiry}/assign", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "assign"])->middleware('permission:screen_providers.edit');
        Route::post("/admin/screen-provider-enquiries/{screenProviderEnquiry}/status", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "status"])->middleware('permission:screen_providers.edit');
        Route::post("/admin/screen-provider-enquiries/{screenProviderEnquiry}/notes", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "notes"])->middleware('permission:screen_providers.edit');
        Route::post("/admin/screen-provider-enquiries/{screenProviderEnquiry}/approve", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "approve"])->middleware('permission:screen_providers.edit');
        Route::post("/admin/screen-provider-enquiries/{screenProviderEnquiry}/reject", [App\Http\Controllers\AdminScreenProviderEnquiryController::class, "reject"])->middleware('permission:screen_providers.edit');
    });

    // Settlements Management
    Route::middleware('permission:settlements.view')->group(function () {
        Route::get('/admin/settlements', [App\Http\Controllers\AdminSettlementController::class, 'index']);
        Route::post('/admin/settlements/generate', [App\Http\Controllers\AdminSettlementController::class, 'generate'])->middleware('permission:settlements.approve');
        Route::get('/admin/settlements/{settlement}', [App\Http\Controllers\AdminSettlementController::class, 'show']);
        Route::put('/admin/settlements/{settlement}', [App\Http\Controllers\AdminSettlementController::class, 'update'])->middleware('permission:settlements.approve');
        Route::post('/admin/settlements/{settlement}/approve', [App\Http\Controllers\AdminSettlementController::class, 'approve'])->middleware('permission:settlements.approve');
        Route::post('/admin/settlements/{settlement}/mark-paid', [App\Http\Controllers\AdminSettlementController::class, 'markPaid'])->middleware('permission:settlements.pay');
        Route::post('/admin/settlements/{settlement}/cancel', [App\Http\Controllers\AdminSettlementController::class, 'cancel'])->middleware('permission:settlements.approve');
    });

    // Screen Providers Management
    Route::middleware('permission:screen_providers.view')->group(function () {
        Route::get('/admin/screen-providers', [App\Http\Controllers\AdminScreenProviderController::class, 'index']);
        Route::get('/admin/screen-providers/all', [App\Http\Controllers\AdminScreenProviderController::class, 'allProviders']);
        Route::post('/admin/screen-providers', [App\Http\Controllers\AdminScreenProviderController::class, 'store'])->middleware('permission:screen_providers.create');
        Route::get('/admin/screen-providers/{screenProvider}', [App\Http\Controllers\AdminScreenProviderController::class, 'show']);
        Route::put('/admin/screen-providers/{screenProvider}', [App\Http\Controllers\AdminScreenProviderController::class, 'update'])->middleware('permission:screen_providers.edit');
        Route::get('/admin/screen-providers/{screenProvider}/activity', [App\Http\Controllers\AdminScreenProviderController::class, 'activity']);
        Route::post('/admin/screen-providers/{screenProvider}/notes', [App\Http\Controllers\AdminScreenProviderController::class, 'addNote'])->middleware('permission:screen_providers.edit');
        Route::put('/admin/screen-providers/{screenProvider}/revenue-split', [App\Http\Controllers\AdminScreenProviderController::class, 'updateRevenueSplit'])->middleware('permission:screen_providers.edit');
        Route::get('/admin/screen-providers/{screenProvider}/screens', [App\Http\Controllers\AdminScreenProviderController::class, 'screens']);
        Route::get('/admin/screen-providers/{screenProvider}/revenue', [App\Http\Controllers\AdminScreenProviderController::class, 'revenue']);
        Route::get('/admin/screen-providers/{screenProvider}/screen-revenue', [App\Http\Controllers\AdminScreenProviderController::class, 'screenRevenue']);
        Route::get('/admin/screen-providers/{screenProvider}/booking-history', [App\Http\Controllers\AdminScreenProviderController::class, 'bookingHistory']);
        Route::get('/admin/screen-providers/{screenProvider}/settlements', [App\Http\Controllers\AdminScreenProviderController::class, 'settlements']);
        Route::post('/admin/screen-providers/{screenProvider}/send-invitation', [App\Http\Controllers\AdminScreenProviderController::class, 'sendPortalInvitation'])->middleware('permission:screen_providers.create');
        Route::post('/admin/screen-providers/{screenProvider}/delete-screens', [App\Http\Controllers\AdminScreenProviderController::class, 'deleteScreens'])->middleware('permission:screen_providers.edit');
        Route::get('/reports/screen-providers', [App\Http\Controllers\ReportController::class, 'screenProviders']);
    });

    // Screen / Location Management
    Route::get('/admin/assets/export', [ScreenController::class, 'export']);
    Route::post('/screens', [ScreenController::class, 'store'])->middleware('permission:screens.create');
    Route::put('/screens/{screen}', [ScreenController::class, 'update'])->middleware('permission:screens.edit');
    Route::delete('/screens/{screen}', [ScreenController::class, 'destroy'])->middleware('permission:screens.delete');
    Route::post('/admin/screens/bulk-delete', [ScreenController::class, 'bulkDestroy'])->middleware('permission:screens.delete');
    
    Route::middleware('permission:screens.create')->group(function () {
        Route::post('/cities', [ScreenController::class, 'storeCity']);
        Route::post('/states', [ScreenController::class, 'storeState']);
        Route::post('/countries', [ScreenController::class, 'storeCountry']);
        Route::post('/countries/{country}/states', [ScreenController::class, 'storeState']);
        Route::post('/countries/{country}/cities', [ScreenController::class, 'storeCity']);
    });

    // Reports & Analytics
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports/revenue', [ReportController::class, 'revenue']);
        Route::get('/reports/bookings', [ReportController::class, 'bookings']);
        Route::get('/reports/screens', [ReportController::class, 'screens']);
        Route::get('/reports/cities', [ReportController::class, 'cities']);
        Route::get('/reports/campaigns', [ReportController::class, 'campaigns']);
        Route::get('/reports/categories', [ReportController::class, 'categories']);
        Route::get('/reports/referrals', [ReportController::class, 'referrals']);
        Route::get('/reports/export', [ReportController::class, 'export'])->middleware('permission:reports.export');
        Route::get('/reports/rewards', [ReportController::class, 'rewards']);
    });

    // Client Management
    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients/kpis', [ClientController::class, 'kpis']);
        Route::get('/clients/{id}/bookings', [ClientController::class, 'bookings']);
        Route::get('/clients/{id}/payments', [ClientController::class, 'payments']);
        Route::get('/clients/{id}/analytics', [ClientController::class, 'analytics']);
        Route::get('/clients/{id}/referral', [ClientController::class, 'referral']);
        Route::get('/clients/{id}/timeline', [ClientController::class, 'timeline']);
        Route::apiResource('clients', ClientController::class)->only([
            'index', 'show',
        ]);
    });

    // Dashboard (authenticated — admin data)
    Route::get('/dashboard', [DashBoardController::class, 'index'])->middleware('permission:dashboard.view');

    // Manual / Offline Bookings (Admin Sales Operations)
    Route::middleware('permission:bookings.create')->group(function () {
        Route::get('/admin/manual-bookings/search-clients', [AdminManualBookingController::class, 'searchClients']);
        Route::post('/admin/manual-bookings/calculate', [AdminManualBookingController::class, 'calculate']);
        Route::post('/admin/manual-bookings', [AdminManualBookingController::class, 'store']);
        Route::get('/admin/manual-bookings/{booking}', [AdminManualBookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('/admin/manual-bookings/{booking}/payment', [AdminManualBookingController::class, 'payment']);
        Route::post('/admin/manual-bookings/{booking}/approve', [AdminManualBookingController::class, 'approve'])->middleware('permission:bookings.approve');
        Route::post('/admin/manual-bookings/{booking}/media', [AdminManualBookingController::class, 'uploadMedia']);
    });

    // Payments — Admin Operations
    Route::middleware('permission:payments.view')->group(function () {
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::get('/payments/analytics', [AdminPaymentController::class, 'analytics']);
        Route::get('/payments/refunds', [AdminPaymentController::class, 'refunds']);
        Route::get('/payments/{id}', [AdminPaymentController::class, 'show']);
        Route::get('/payments/{id}/timeline', [AdminPaymentController::class, 'timeline']);
        Route::post('/payments/{id}/refund', [AdminPaymentController::class, 'initiateRefund'])->middleware('permission:payments.approve');
    });

    // File Uploads
    Route::post('/file-upload', [FileController::class, 'upload']);
    Route::post('/video-upload', [FileController::class, 'videoupload']);

    // Pricing Plans
    Route::middleware('permission:pricing.view')->group(function () {
        Route::post('/pricing-plans', [PricingPlanController::class, 'store'])->middleware('permission:pricing.create');
        Route::put('/pricing-plans/{pricingPlan}', [PricingPlanController::class,'update'])->middleware('permission:pricing.edit');
        Route::delete('/pricing-plans/{pricingPlan}', [PricingPlanController::class,'destroy'])->middleware('permission:pricing.delete');
    });

    // Bookings CRUD and Admin Bookings management
    Route::middleware('permission:bookings.view')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        // SEC-01 fix: single registration for BOTH verbs, inside the permission group.
        // Replaces the apiResource 'update' entry that registered PUT|PATCH ungated.
        Route::match(['put', 'patch'], '/bookings/{booking}', [BookingController::class, 'update'])
            ->middleware('permission:bookings.edit');
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->middleware('permission:bookings.delete');
        
        Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve'])->middleware('permission:bookings.approve');
        Route::post('/bookings/{booking}/reject', [BookingController::class, 'reject'])->middleware('permission:bookings.approve');
        Route::get('/bookings/{booking}/review', [BookingController::class, 'review'])->middleware('permission:bookings.approve');
        Route::post('/bookings/{booking}/mark-media-review', [BookingController::class, 'markMediaReview'])->middleware('permission:bookings.approve');
        Route::post('/bookings/{booking}/media/{media}/approve', [BookingController::class, 'approveMedia'])->middleware('permission:bookings.approve');
        Route::post('/bookings/{booking}/media/{media}/reject', [BookingController::class, 'rejectMedia'])->middleware('permission:bookings.approve');
        
        Route::post('/bookings/{booking}/start-session', [BookingController::class, 'startSession'])->middleware('permission:bookings.edit');
        Route::post('/bookings/{booking}/end-session', [BookingController::class, 'endSession'])->middleware('permission:bookings.edit');
        Route::post('/bookings/{booking}/extend-session', [BookingController::class, 'extendSession'])->middleware('permission:bookings.edit');
        Route::post('/bookings/{booking}/manual-payment', [BookingController::class, 'markManualPayment'])->middleware('permission:bookings.edit');
        Route::post('/bookings/{booking}/resend-confirmation', [BookingController::class, 'resendConfirmation'])->middleware('permission:bookings.edit');
        Route::post('/bookings/{booking}/notes', [BookingController::class, 'addNote'])->middleware('permission:bookings.edit');
        Route::get('/bookings/{booking}/timeline', [BookingController::class, 'timeline']);
    });

    // QR Codes
    Route::middleware('permission:screens.view')->group(function () {
        Route::apiResource('qr-codes', QrCodeController::class);
        Route::get('/qr-codes/{id}/analytics', [QrCodeController::class, 'analytics']);
    });
});// Screen Provider Enquiries (Public)
Route::post('/screen-provider-enquiries', [App\Http\Controllers\ScreenProviderEnquiryController::class, 'store']);

Route::apiResource('gallery', GalleryController::class);

// Contact Enquiries (Public)
Route::post('/contact-enquiries', [App\Http\Controllers\ContactEnquiryController::class, 'store']);

// ─── Currency & Exchange Rate Routes ──────────────────────

// Public endpoints
Route::get('/currencies', [App\Http\Controllers\Api\CurrencyController::class, 'currencies']);
Route::get('/currency/context', [App\Http\Controllers\Api\CurrencyController::class, 'context']);
Route::get('/currency/rates', [App\Http\Controllers\Api\CurrencyController::class, 'rates']);
Route::post('/currency/convert', [App\Http\Controllers\Api\CurrencyController::class, 'convert']);

// Admin-only currency management
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/currency-rates', [App\Http\Controllers\Api\AdminCurrencyController::class, 'index']);
    Route::post('/admin/currency-rates/sync', [App\Http\Controllers\Api\AdminCurrencyController::class, 'sync']);
});
