<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFreelancerServiceRequest;
use App\Http\Requests\StorePortfolioItemRequest;
use App\Http\Requests\UpdateFreelancerProfileRequest;
use App\Http\Requests\UpdateFreelancerServiceRequest;
use App\Http\Resources\FreelancerProfileResource;
use App\Http\Resources\FreelancerServiceResource;
use App\Http\Resources\PortfolioItemResource;
use App\Models\FreelanceCategory;
use App\Models\FreelanceCategoryRequest;
use App\Models\FreelancerProfile;
use App\Models\FreelancerProfileMedia;
use App\Models\FreelancerService;
use App\Models\FreelancerServicePackage;
use App\Models\FreelancerSkill;
use App\Models\FreelancerUnavailability;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Services\Freelance\ContentSafetyService;
use App\Services\Freelance\ServicePricingEstimatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreelancingController extends Controller
{
    public function __construct(
        protected StorageContractInterface $storage,
        protected ContentSafetyService $safety,
        protected ServicePricingEstimatorService $pricingEstimator,
    ) {}

    public function freelancers(Request $request): JsonResponse
    {
        $query = FreelancerProfile::query()
            ->with(['user:id,name,avatar', 'skills', 'profileMedia', 'unavailabilities'])
            ->withCount(['services as active_services_count' => fn ($q) => $q->where('status', 'active')->where('moderation_status', 'approved')]);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('professional_title', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('skills', fn ($skills) => $skills->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('min_rating')) {
            $query->where('rating', '>=', (float) $request->query('min_rating'));
        }

        $query->when($request->query('sort') === 'rating', fn ($q) => $q->orderByDesc('rating'))
            ->when($request->query('sort') === 'price_asc', fn ($q) => $q->orderBy('hourly_rate'))
            ->latest();

        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Freelancers fetched successfully.');
    }

    public function showFreelancer($freelancerProfile): JsonResponse
    {
        $profile = FreelancerProfile::where('id', $freelancerProfile)
            ->orWhere('user_id', $freelancerProfile)
            ->first();

        if (!$profile) {
            abort(404, 'Freelancer profile not found.');
        }

        $profile->load([
            'user:id,name,avatar',
            'skills',
            'profileMedia',
            'unavailabilities',
            'services' => fn ($query) => $query->whereIn('status', ['active', 'published'])->with('packages', 'categoryModel'),
            'portfolioItems' => fn ($query) => $query->where('visibility', 'public')->with('media', 'categoryModel'),
            'reviews' => fn ($query) => $query->with('client:id,name,avatar', 'project:id,title', 'reply')->latest()->limit(20),
        ]);
        return $this->successResponse(new FreelancerProfileResource($profile), 'Freelancer fetched successfully.');
    }

    public function services(Request $request): JsonResponse
    {
        $query = FreelancerService::query()
            ->whereIn('status', ['active', 'published'])
            ->where(function ($q) {
                $q->where('moderation_status', 'approved')
                  ->orWhereNull('moderation_status')
                  ->orWhere('moderation_status', 'pending_review');
            })
            ->with('packages', 'categoryModel', 'freelancerProfile.user:id,name,avatar', 'freelancerProfile.skills', 'freelancerProfile.unavailabilities');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereJsonContains('skills', $search);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        } elseif ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        $query->latest();

        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Freelancer services fetched successfully.');
    }

    public function showService($serviceId): JsonResponse
    {
        $freelancerService = FreelancerService::find($serviceId);
        if (!$freelancerService) {
            $freelancerService = FreelancerService::where('slug', $serviceId)->first();
        }
        abort_unless($freelancerService !== null, 404, 'Service not found.');

        return $this->successResponse(new FreelancerServiceResource(
            $freelancerService->load('packages', 'categoryModel', 'freelancerProfile.user:id,name,avatar', 'freelancerProfile.skills', 'freelancerProfile.unavailabilities')
        ), 'Service fetched successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        return $this->successResponse(new FreelancerProfileResource(
            $profile->load('user:id,name,avatar', 'skills', 'profileMedia', 'unavailabilities', 'services.packages', 'portfolioItems.media')
        ), 'Freelancer profile fetched successfully.');
    }

    public function updateProfile(UpdateFreelancerProfileRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validated();

        $contentToScan = ($data['bio'] ?? '') . ' ' . ($data['professional_title'] ?? '');
        $safetyCheck = $this->safety->scanText($contentToScan);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation(
                $request->user()->id,
                'profile_update',
                $contentToScan,
                $safetyCheck['violations'],
                $safetyCheck['detected_patterns']
            );
            return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
        }

        if (array_key_exists('avatar', $data)) {
            $avatarVal = $data['avatar'];
            if (!empty($avatarVal) && str_starts_with($avatarVal, 'data:image')) {
                if (preg_match('/^data:image\/(\w+);base64,/', $avatarVal, $type)) {
                    $image = substr($avatarVal, strpos($avatarVal, ',') + 1);
                    $type = strtolower($type[1]);
                    if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
                        $type = 'png';
                    }
                    $image = str_replace(' ', '+', $image);
                    $decoded = base64_decode($image);
                    if ($decoded !== false) {
                        $filename = 'avatars/avatar_' . $request->user()->id . '_' . time() . '.' . $type;
                        \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $decoded);
                        $request->user()->update(['avatar' => $filename]);
                    }
                }
            } elseif ($avatarVal === null || $avatarVal === '') {
                $request->user()->update(['avatar' => null]);
            } else {
                $cleanPath = preg_replace('/^https?:\/\/[^\/]+\/storage\//', '', $avatarVal);
                $request->user()->update(['avatar' => $cleanPath]);
            }
            unset($data['avatar']);
        }

        if (isset($data['social_links']) && is_array($data['social_links'])) {
            $cleaned = [];
            foreach ($data['social_links'] as $k => $v) {
                if ($v !== null && trim((string) $v) !== '') {
                    $url = trim((string) $v);
                    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                        $url = 'https://' . $url;
                    }
                    $cleaned[$k] = $url;
                }
            }
            $data['social_links'] = $cleaned;
        }

        if (isset($data['response_time'])) {
            $rtMap = [
                '1 Hour' => 3600,
                '2 Hours' => 7200,
                'Within 12 Hours' => 43200,
                'Within 24 Hours' => 86400,
            ];
            $data['response_time_seconds'] = $rtMap[$data['response_time']] ?? 3600;
        }

        $profile->update($data);
        return $this->successResponse(new FreelancerProfileResource($profile->fresh(['skills', 'user:id,name,avatar', 'profileMedia', 'unavailabilities'])), 'Freelancer profile updated successfully.');
    }

    public function updateSkills(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skills' => 'nullable|array|max:50',
            'skills.*' => 'required|string|max:80'
        ]);
        $profile = $this->profileFor($request);

        DB::transaction(function () use ($profile, $validated) {
            $profile->skills()->delete();
            $skillsList = $validated['skills'] ?? [];
            foreach (array_values(array_unique($skillsList)) as $name) {
                $cleanName = trim((string) $name);
                if ($cleanName !== '') {
                    $profile->skills()->create(['name' => $cleanName, 'slug' => Str::slug($cleanName)]);
                }
            }
        });

        return $this->successResponse(new FreelancerProfileResource($profile->fresh('skills')), 'Freelancer skills updated successfully.');
    }

    public function storeService(StoreFreelancerServiceRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validated();

        $contentToScan = ($data['title'] ?? '') . ' ' . ($data['description'] ?? '');
        $safetyCheck = $this->safety->scanText($contentToScan);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation(
                $request->user()->id,
                'service_creation',
                $contentToScan,
                $safetyCheck['violations'],
                $safetyCheck['detected_patterns']
            );
            return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
        }

        $packages = $data['packages'];
        unset($data['packages']);

        if ($request->hasFile('featured_image')) {
            $data['featured_image'] = $this->storeFile($request->file('featured_image'), "freelancers/{$request->user()->id}/services", 'image');
        } elseif (isset($data['featured_image']) && is_string($data['featured_image'])) {
            $data['featured_image'] = $this->saveBase64Image($data['featured_image'], "freelancers/{$request->user()->id}/services");
        }

        if (isset($data['gallery']) && is_array($data['gallery'])) {
            $galleryPaths = [];
            foreach ($data['gallery'] as $galleryItem) {
                if ($galleryItem) {
                    $savedPath = $this->saveBase64Image($galleryItem, "freelancers/{$request->user()->id}/services");
                    if ($savedPath) $galleryPaths[] = $savedPath;
                }
            }
            $data['gallery'] = $galleryPaths;
        }

        if (empty($data['category_id']) && !empty($data['category'])) {
            $cat = FreelanceCategory::where('name', $data['category'])->orWhere('slug', Str::slug($data['category']))->first();
            if ($cat) $data['category_id'] = $cat->id;
        }

        if ($request->hasFile('video')) {
            $videoFile = $request->file('video');
            $data['video_path'] = $this->storeFile($videoFile, "freelancers/{$request->user()->id}/services/videos", 'video');
            $data['video_status'] = 'pending';
            $data['moderation_status'] = 'pending_review';
        }

        if (isset($data['status']) && $data['status'] === 'published') {
            $data['status'] = 'active';
        }

        $service = DB::transaction(function () use ($profile, $data, $packages) {
            $service = $profile->services()->create([
                ...$data,
                'slug' => $this->uniqueSlug($data['title']),
                'status' => $data['status'] ?? 'draft',
                'moderation_status' => $data['moderation_status'] ?? (($data['status'] ?? '') === 'active' ? 'approved' : 'pending_review'),
            ]);
            $this->syncPackages($service, $packages);
            return $service;
        });

        return $this->successResponse(new FreelancerServiceResource($service->load('packages', 'categoryModel')), 'Freelancer service submitted for admin review.', 201);
    }

    public function updateService(UpdateFreelancerServiceRequest $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        $data = $request->validated();

        $contentToScan = ($data['title'] ?? $freelancerService->title) . ' ' . ($data['description'] ?? $freelancerService->description);
        $safetyCheck = $this->safety->scanText($contentToScan);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation(
                $request->user()->id,
                'service_update',
                $contentToScan,
                $safetyCheck['violations'],
                $safetyCheck['detected_patterns']
            );
            return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
        }

        $packages = $data['packages'] ?? null;
        unset($data['packages']);

        if ($request->hasFile('featured_image')) {
            $data['featured_image'] = $this->storeFile($request->file('featured_image'), "freelancers/{$request->user()->id}/services", 'image');
        } elseif (isset($data['featured_image']) && is_string($data['featured_image'])) {
            $data['featured_image'] = $this->saveBase64Image($data['featured_image'], "freelancers/{$request->user()->id}/services");
        }

        if (isset($data['gallery']) && is_array($data['gallery'])) {
            $galleryPaths = [];
            foreach ($data['gallery'] as $galleryItem) {
                if ($galleryItem) {
                    $savedPath = $this->saveBase64Image($galleryItem, "freelancers/{$request->user()->id}/services");
                    if ($savedPath) $galleryPaths[] = $savedPath;
                }
            }
            $data['gallery'] = $galleryPaths;
        }

        if (empty($data['category_id']) && !empty($data['category'])) {
            $cat = FreelanceCategory::where('name', $data['category'])->orWhere('slug', Str::slug($data['category']))->first();
            if ($cat) $data['category_id'] = $cat->id;
        }

        if ($request->hasFile('video')) {
            $videoFile = $request->file('video');
            $data['video_path'] = $this->storeFile($videoFile, "freelancers/{$request->user()->id}/services/videos", 'video');
            $data['video_status'] = 'pending';
            $data['moderation_status'] = 'pending_review';
        }

        if (isset($data['title']) && $data['title'] !== $freelancerService->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $freelancerService->id);
        }

        if (isset($data['status']) && $data['status'] === 'published') {
            $data['status'] = 'active';
        }

        if (!isset($data['moderation_status'])) {
            $data['moderation_status'] = ($data['status'] ?? $freelancerService->status) === 'active' ? 'approved' : 'pending_review';
        }

        DB::transaction(function () use ($freelancerService, $data, $packages) {
            $freelancerService->update($data);
            if ($packages !== null) $this->syncPackages($freelancerService, $packages);
        });

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh(['packages', 'categoryModel'])), 'Freelancer service updated successfully.');
    }

    public function pauseService(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        $request->validate(['reason' => 'nullable|string|max:255']);

        $freelancerService->update([
            'is_paused' => true,
            'paused_at' => now(),
            'pause_reason' => $request->input('reason', 'Paused by freelancer'),
        ]);

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh(['packages', 'categoryModel'])), 'Service paused. It remains visible with "Temporarily Unavailable" status.');
    }

    public function resumeService(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);

        if ($freelancerService->moderation_status !== 'approved') {
            return $this->errorResponse('Only approved services can be resumed. Your service moderation status is: ' . $freelancerService->moderation_status, 422);
        }

        $freelancerService->update([
            'is_paused' => false,
            'paused_at' => null,
            'pause_reason' => null,
        ]);

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh(['packages', 'categoryModel'])), 'Service resumed successfully.');
    }

    public function uploadServiceVideo(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        $request->validate([
            'video' => 'required|file|mimes:mp4,webm,mov|max:102400',
        ]);

        $path = $this->storeFile($request->file('video'), "freelancers/{$request->user()->id}/services/{$freelancerService->id}/video", 'video');

        $freelancerService->update([
            'video_path' => $path,
            'video_status' => 'pending',
            'moderation_status' => 'pending_review',
        ]);

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh(['packages', 'categoryModel'])), 'Video uploaded successfully and queued for admin moderation.');
    }

    public function deleteServiceVideo(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        if ($freelancerService->video_path) {
            $this->storage->delete($freelancerService->video_path);
        }
        $freelancerService->update([
            'video_path' => null,
            'video_thumbnail' => null,
            'video_status' => null,
        ]);

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh(['packages', 'categoryModel'])), 'Service video removed successfully.');
    }

    public function destroyService(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        $freelancerService->delete();
        return $this->successResponse(null, 'Freelancer service deleted successfully.');
    }

    public function storePortfolio(StorePortfolioItemRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validated();

        $contentToScan = ($data['title'] ?? '') . ' ' . ($data['description'] ?? '');
        $safetyCheck = $this->safety->scanText($contentToScan);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation(
                $request->user()->id,
                'portfolio_creation',
                $contentToScan,
                $safetyCheck['violations'],
                $safetyCheck['detected_patterns']
            );
            return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
        }

        $data['moderation_status'] = $data['moderation_status'] ?? (($data['visibility'] ?? 'public') === 'public' ? 'approved' : 'draft');
        $item = $profile->portfolioItems()->create($data);
        return $this->successResponse(new PortfolioItemResource($item->load('media', 'categoryModel')), 'Portfolio item saved successfully.', 201);
    }

    public function updatePortfolio(StorePortfolioItemRequest $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        $data = $request->validated();

        $contentToScan = ($data['title'] ?? $portfolioItem->title) . ' ' . ($data['description'] ?? $portfolioItem->description);
        $safetyCheck = $this->safety->scanText($contentToScan);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation(
                $request->user()->id,
                'portfolio_update',
                $contentToScan,
                $safetyCheck['violations'],
                $safetyCheck['detected_patterns']
            );
            return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
        }

        if (!isset($data['moderation_status'])) {
            $data['moderation_status'] = ($data['visibility'] ?? $portfolioItem->visibility) === 'public' ? 'approved' : 'draft';
        }
        if (($data['moderation_status'] ?? '') === 'approved' && !isset($data['status'])) {
            $data['status'] = 'published';
        }
        $portfolioItem->update($data);
        return $this->successResponse(new PortfolioItemResource($portfolioItem->fresh(['media', 'categoryModel'])), 'Portfolio item updated successfully.');
    }

    public function approveMyPortfolio(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        $portfolioItem->update([
            'status' => 'published',
            'visibility' => 'public',
            'moderation_status' => 'approved',
        ]);
        return $this->successResponse(new PortfolioItemResource($portfolioItem->fresh(['media', 'categoryModel'])), 'Portfolio item approved and published successfully.');
    }

    public function destroyPortfolio(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        foreach ($portfolioItem->media as $media) $this->storage->delete($media->path);
        $portfolioItem->delete();
        return $this->successResponse(null, 'Portfolio item deleted successfully.');
    }

    public function storePortfolioMedia(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
        $path = $this->storeFile($file, "freelancers/{$request->user()->id}/portfolio/{$portfolioItem->id}/{$type}", $type);
        $media = $portfolioItem->media()->create([
            'uploaded_by' => $request->user()->id,
            'media_type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'file_name' => basename($path),
            'path' => $path,
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'sort_order' => ((int) $portfolioItem->media()->max('sort_order')) + 1,
            'moderation_status' => 'pending',
            'is_cover' => $portfolioItem->media()->count() === 0,
        ]);

        return $this->successResponse((new PortfolioItemResource($portfolioItem->fresh('media'))), 'Portfolio media uploaded successfully and submitted for moderation.', 201);
    }

    public function setPortfolioCover(Request $request, PortfolioItem $portfolioItem, PortfolioMedia $portfolioMedia): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        abort_unless($portfolioMedia->portfolio_item_id === $portfolioItem->id, 404);

        $portfolioItem->media()->update(['is_cover' => false]);
        $portfolioMedia->update(['is_cover' => true]);

        return $this->successResponse(new PortfolioItemResource($portfolioItem->fresh('media')), 'Cover media set successfully.');
    }

    public function reorderPortfolioMedia(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        $data = $request->validate([
            'media_ids' => 'required|array',
            'media_ids.*' => 'integer|exists:portfolio_media,id',
        ]);

        foreach ($data['media_ids'] as $index => $id) {
            $portfolioItem->media()->where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return $this->successResponse(new PortfolioItemResource($portfolioItem->fresh('media')), 'Portfolio media order updated successfully.');
    }

    public function destroyPortfolioMedia(Request $request, PortfolioItem $portfolioItem, PortfolioMedia $portfolioMedia): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        abort_unless($portfolioMedia->portfolio_item_id === $portfolioItem->id, 404);
        $this->storage->delete($portfolioMedia->path);
        $portfolioMedia->delete();
        return $this->successResponse(null, 'Portfolio media deleted successfully.');
    }

    // FEATURE 2 & 5: Categories & Category Requests
    public function categories(Request $request): JsonResponse
    {
        $type = $request->query('type', 'all');
        $query = FreelanceCategory::with('children')
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($type !== 'all') {
            $query->where(fn ($q) => $q->where('type', $type)->orWhere('type', 'both'));
        }

        return $this->successResponse($query->get(), 'Approved categories fetched successfully.');
    }

    public function requestCategory(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|exists:freelance_categories,id',
            'type' => 'nullable|in:service,portfolio,both',
            'auto_approve' => 'nullable|boolean',
        ]);

        $exists = FreelanceCategory::where('name', $data['name'])->exists();
        if ($exists) {
            $cat = FreelanceCategory::where('name', $data['name'])->first();
            return $this->successResponse($cat, 'Category already exists and is active.', 200);
        }

        $categoryRequest = $profile->categoryRequests()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'type' => $data['type'] ?? 'service',
            'status' => $request->boolean('auto_approve') ? 'approved' : 'pending',
        ]);

        if ($request->boolean('auto_approve')) {
            $slug = Str::slug($data['name']);
            $count = FreelanceCategory::where('slug', 'like', "{$slug}%")->count();
            if ($count > 0) $slug .= '-' . ($count + 1);

            $createdCategory = FreelanceCategory::create([
                'name' => $data['name'],
                'slug' => $slug,
                'type' => ($data['type'] ?? 'service') === 'portfolio' ? 'portfolio' : 'service',
                'parent_id' => $data['parent_id'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => true,
            ]);

            $categoryRequest->update([
                'created_category_id' => $createdCategory->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'admin_feedback' => 'Self-approved by creator for immediate workspace use.',
            ]);

            return $this->successResponse([
                'request' => $categoryRequest,
                'category' => $createdCategory,
            ], 'Category requested and activated immediately.', 201);
        }

        return $this->successResponse($categoryRequest, 'Category request submitted for review.', 201);
    }

    public function approveMyCategoryRequest(Request $request, FreelanceCategoryRequest $categoryRequest): JsonResponse
    {
        $profile = $this->profileFor($request);
        abort_unless($categoryRequest->freelancer_profile_id === $profile->id || $categoryRequest->user_id === $request->user()->id, 403, 'Unauthorized.');

        $category = DB::transaction(function () use ($categoryRequest, $request) {
            $slug = Str::slug($categoryRequest->name);
            $count = FreelanceCategory::where('slug', 'like', "{$slug}%")->count();
            if ($count > 0) $slug .= '-' . ($count + 1);

            $createdCategory = FreelanceCategory::firstOrCreate(
                ['name' => $categoryRequest->name],
                [
                    'slug' => $slug,
                    'type' => $categoryRequest->type === 'portfolio' ? 'portfolio' : 'service',
                    'parent_id' => $categoryRequest->parent_id,
                    'description' => $categoryRequest->description,
                    'is_active' => true,
                ]
            );

            $categoryRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'created_category_id' => $createdCategory->id,
                'admin_feedback' => 'Approved and activated by freelancer.',
            ]);

            return $createdCategory;
        });

        return $this->successResponse([
            'request' => $categoryRequest->fresh(),
            'category' => $category,
        ], 'Category request approved and activated successfully.');
    }

    public function myCategoryRequests(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        return $this->successResponse(
            $profile->categoryRequests()->with('parent')->latest()->get(),
            'Your category requests fetched successfully.'
        );
    }

    // FEATURE 9: Availability Management
    public function getUnavailabilities(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        return $this->successResponse(
            $profile->unavailabilities()->orderBy('from_date')->get(),
            'Unavailability periods fetched successfully.'
        );
    }

    public function storeUnavailability(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'nullable|string|max:500',
        ]);

        $overlapping = $profile->unavailabilities()
            ->where(function ($q) use ($data) {
                $q->whereBetween('from_date', [$data['from_date'], $data['to_date']])
                  ->orWhereBetween('to_date', [$data['from_date'], $data['to_date']])
                  ->orWhere(fn ($sub) => $sub->where('from_date', '<=', $data['from_date'])->where('to_date', '>=', $data['to_date']));
            })
            ->exists();

        if ($overlapping) {
            return $this->errorResponse('The selected date range overlaps with an existing unavailable period.', 422);
        }

        $record = $profile->unavailabilities()->create($data);
        return $this->successResponse($record, 'Unavailable period scheduled successfully.', 201);
    }

    public function deleteUnavailability(Request $request, FreelancerUnavailability $unavailability): JsonResponse
    {
        $profile = $this->profileFor($request);
        abort_unless($unavailability->freelancer_profile_id === $profile->id, 403);
        $unavailability->delete();
        return $this->successResponse(null, 'Unavailable period removed successfully.');
    }

    // FEATURE 11: Dynamic Price Estimator
    public function estimatePrice(Request $request, FreelancerService $freelancerService): JsonResponse
    {
        $data = $request->validate([
            'package_id' => 'required|exists:freelancer_service_packages,id',
            'quantity' => 'nullable|integer|min:1|max:10',
            'extra_revisions' => 'nullable|integer|min:0|max:20',
            'express_delivery' => 'nullable|boolean',
            'custom_addons' => 'nullable|array',
            'custom_addons.*.name' => 'required|string|max:100',
            'custom_addons.*.price' => 'required|numeric|min:0',
        ]);

        $estimate = $this->pricingEstimator->estimate($freelancerService, $data);
        return $this->successResponse($estimate, 'Price estimate generated successfully.');
    }

    // FEATURE 14: Profile Media Gallery
    public function getProfileMedia(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        return $this->successResponse($profile->profileMedia()->orderBy('sort_order')->get(), 'Profile media fetched successfully.');
    }

    public function storeProfileMedia(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $this->storeFile($file, "freelancers/{$request->user()->id}/profile-media", 'image');
        $isFirst = $profile->profileMedia()->count() === 0;

        $media = $profile->profileMedia()->create([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'is_primary' => $isFirst,
            'sort_order' => ((int) $profile->profileMedia()->max('sort_order')) + 1,
            'moderation_status' => 'approved',
        ]);

        if ($isFirst) {
            $request->user()->update(['avatar' => $path]);
        }

        return $this->successResponse($media, 'Profile image uploaded successfully.', 201);
    }

    public function setPrimaryProfileMedia(Request $request, FreelancerProfileMedia $media): JsonResponse
    {
        $profile = $this->profileFor($request);
        abort_unless($media->freelancer_profile_id === $profile->id, 403);

        $profile->profileMedia()->update(['is_primary' => false]);
        $media->update(['is_primary' => true]);

        $request->user()->update(['avatar' => $media->path]);

        return $this->successResponse($media->fresh(), 'Primary profile image updated successfully.');
    }

    public function deleteProfileMedia(Request $request, FreelancerProfileMedia $media): JsonResponse
    {
        $profile = $this->profileFor($request);
        abort_unless($media->freelancer_profile_id === $profile->id, 403);

        $wasPrimary = $media->is_primary;
        $this->storage->delete($media->path);
        $media->delete();

        if ($wasPrimary) {
            $next = $profile->profileMedia()->orderBy('sort_order')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
                $request->user()->update(['avatar' => $next->path]);
            }
        }

        return $this->successResponse(null, 'Profile image deleted successfully.');
    }

    public function reorderProfileMedia(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validate([
            'media_ids' => 'required|array',
            'media_ids.*' => 'integer|exists:freelancer_profile_media,id',
        ]);

        foreach ($data['media_ids'] as $index => $id) {
            $profile->profileMedia()->where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return $this->successResponse($profile->profileMedia()->orderBy('sort_order')->get(), 'Profile media order updated successfully.');
    }

    private function profileFor(Request $request): FreelancerProfile
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        return FreelancerProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['professional_title' => 'Creative Professional', 'hourly_rate' => 500]
        );
    }

    private function ensureServiceOwner(Request $request, FreelancerService $service): void
    {
        abort_unless($service->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function ensurePortfolioOwner(Request $request, PortfolioItem $item): void
    {
        abort_unless($item->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function ownsService(Request $request, FreelancerService $service): bool
    {
        return $request->user() && $service->freelancerProfile()->where('user_id', $request->user()->id)->exists();
    }

    private function syncPackages(FreelancerService $service, array $packages): void
    {
        $service->packages()->delete();
        foreach ($packages as $package) $service->packages()->create($package);
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'service';
        $slug = $base;
        $counter = 2;
        while (FreelancerService::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$counter++;
        }
        return $slug;
    }

    private function storeFile(UploadedFile $file, string $directory, string $type): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        return $this->storage->storeAs($file, $directory, Str::uuid()->toString().($extension ? ".{$extension}" : ''));
    }

    private function saveBase64Image(?string $value, string $directory): ?string
    {
        if (!$value) return null;
        if (!preg_match('/^data:image\/(\w+);base64,/', $value, $matches)) {
            $storagePrefix = asset('storage') . '/';
            if (str_starts_with($value, $storagePrefix)) {
                return substr($value, strlen($storagePrefix));
            }
            return $value;
        }

        $extension = strtolower($matches[1]);
        if ($extension === 'jpeg') $extension = 'jpg';
        $imageContent = base64_decode(substr($value, strpos($value, ',') + 1));
        if ($imageContent === false) return null;

        $fileName = Str::uuid()->toString() . '.' . $extension;
        $path = $directory . '/' . $fileName;
        $this->storage->put($path, $imageContent);
        return $path;
    }
}
