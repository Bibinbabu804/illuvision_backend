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
use App\Models\FreelancerProfile;
use App\Models\FreelancerService;
use App\Models\FreelancerSkill;
use App\Models\FreelancerServicePackage;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreelancingController extends Controller
{
    public function __construct(protected StorageContractInterface $storage) {}

    public function freelancers(Request $request): JsonResponse
    {
        $query = FreelancerProfile::query()
            ->with('user:id,name,avatar', 'skills')
            ->withCount(['services as active_services_count' => fn ($q) => $q->where('status', 'active')]);

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

    public function showFreelancer(FreelancerProfile $freelancerProfile): JsonResponse
    {
        $freelancerProfile->load([
            'user:id,name,avatar',
            'skills',
            'services' => fn ($query) => $query->where('status', 'active')->with('packages'),
            'portfolioItems' => fn ($query) => $query->where('visibility', 'public')->where('status', 'published')->with('media'),
            'reviews' => fn ($query) => $query->with('client:id,name,avatar', 'project:id,title')->latest()->limit(15),
        ]);
        return $this->successResponse(new FreelancerProfileResource($freelancerProfile), 'Freelancer fetched successfully.');
    }

    public function services(Request $request): JsonResponse
    {
        $query = FreelancerService::query()
            ->where('status', 'active')
            ->with('packages', 'freelancerProfile.user:id,name,avatar');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereJsonContains('skills', $search);
            });
        }

        $query->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->latest();

        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Freelancer services fetched successfully.');
    }

    public function showService(FreelancerService $freelancerService): JsonResponse
    {
        abort_unless($freelancerService->status === 'active' || $this->ownsService(request(), $freelancerService), 404);
        return $this->successResponse(new FreelancerServiceResource($freelancerService->load('packages', 'freelancerProfile.user:id,name,avatar')), 'Service fetched successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        return $this->successResponse(new FreelancerProfileResource($profile->load('user:id,name,avatar', 'skills', 'services.packages', 'portfolioItems.media')), 'Freelancer profile fetched successfully.');
    }

    public function updateProfile(UpdateFreelancerProfileRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validated();
        if (array_key_exists('avatar', $data)) {
            $request->user()->update(['avatar' => $data['avatar']]);
            unset($data['avatar']);
        }
        $profile->update($data);
        return $this->successResponse(new FreelancerProfileResource($profile->fresh(['skills', 'user:id,name,avatar'])), 'Freelancer profile updated successfully.');
    }

    public function updateSkills(Request $request): JsonResponse
    {
        $validated = $request->validate(['skills' => 'required|array|max:50', 'skills.*' => 'required|string|max:80']);
        $profile = $this->profileFor($request);

        DB::transaction(function () use ($profile, $validated) {
            $profile->skills()->delete();
            foreach (array_values(array_unique($validated['skills'])) as $name) {
                $profile->skills()->create(['name' => $name, 'slug' => Str::slug($name)]);
            }
        });

        return $this->successResponse(new FreelancerProfileResource($profile->fresh('skills')), 'Freelancer skills updated successfully.');
    }

    public function storeService(StoreFreelancerServiceRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request);
        $data = $request->validated();
        $packages = $data['packages'];
        unset($data['packages']);

        if ($request->hasFile('featured_image')) {
            $data['featured_image'] = $this->storeFile($request->file('featured_image'), "freelancers/{$request->user()->id}/services", 'image');
        }

        $service = DB::transaction(function () use ($profile, $data, $packages) {
            $service = $profile->services()->create([
                ...$data,
                'slug' => $this->uniqueSlug($data['title']),
                'status' => $data['status'] ?? 'draft',
            ]);
            $this->syncPackages($service, $packages);
            return $service;
        });

        return $this->successResponse(new FreelancerServiceResource($service->load('packages')), 'Freelancer service created successfully.', 201);
    }

    public function updateService(UpdateFreelancerServiceRequest $request, FreelancerService $freelancerService): JsonResponse
    {
        $this->ensureServiceOwner($request, $freelancerService);
        $data = $request->validated();
        $packages = $data['packages'] ?? null;
        unset($data['packages']);

        if ($request->hasFile('featured_image')) {
            $data['featured_image'] = $this->storeFile($request->file('featured_image'), "freelancers/{$request->user()->id}/services", 'image');
        }
        if (isset($data['title']) && $data['title'] !== $freelancerService->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $freelancerService->id);
        }

        DB::transaction(function () use ($freelancerService, $data, $packages) {
            $freelancerService->update($data);
            if ($packages !== null) $this->syncPackages($freelancerService, $packages);
        });

        return $this->successResponse(new FreelancerServiceResource($freelancerService->fresh('packages')), 'Freelancer service updated successfully.');
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
        $item = $profile->portfolioItems()->create($request->validated());
        return $this->successResponse(new PortfolioItemResource($item->load('media')), 'Portfolio item created successfully.', 201);
    }

    public function updatePortfolio(StorePortfolioItemRequest $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        $portfolioItem->update($request->validated());
        return $this->successResponse(new PortfolioItemResource($portfolioItem->fresh('media')), 'Portfolio item updated successfully.');
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
        ]);

        return $this->successResponse((new PortfolioItemResource($portfolioItem->fresh('media'))), 'Portfolio media uploaded successfully.', 201);
    }

    public function destroyPortfolioMedia(Request $request, PortfolioItem $portfolioItem, PortfolioMedia $portfolioMedia): JsonResponse
    {
        $this->ensurePortfolioOwner($request, $portfolioItem);
        abort_unless($portfolioMedia->portfolio_item_id === $portfolioItem->id, 404);
        $this->storage->delete($portfolioMedia->path);
        $portfolioMedia->delete();
        return $this->successResponse(null, 'Portfolio media deleted successfully.');
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
}
