<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Services\Campaign\ReferralService;
use Illuminate\Http\Request;

class ReferralCodeController extends Controller
{
    protected $referralService;

    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 10);
        $status = $request->input('status');
        $partnerId = $request->input('partner_id');

        $codes = ReferralCode::with(['partner'])
            ->withCount('usages')
            ->withSum('usages', 'discount_amount')
            ->withSum('usages', 'commission_amount')
            ->when($search, function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%");
            })
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($partnerId, function ($query) use ($partnerId) {
                $query->where('partner_id', $partnerId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($codes, 'Referral codes fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'code' => 'required|string|max:50|unique:referral_codes,code',
            'status' => 'required|in:draft,active,paused,expired',
            'start_date' => 'required|date',
            'expiry_date' => 'required|date|after_or_equal:start_date',
            'usage_limit' => 'required|integer|min:0',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'commission_type' => 'required|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0',
            'max_commission_amount' => 'nullable|numeric|min:0',
        ]);

        $code = ReferralCode::create($validated);
        $code->load('partner');
        $code->loadCount('usages');

        return $this->successResponse($code, 'Referral code created successfully', 201);
    }

    public function show(ReferralCode $referralCode)
    {
        $referralCode->load(['partner', 'usages']);
        $referralCode->loadCount('usages');
        $referralCode->loadSum('usages', 'discount_amount');
        $referralCode->loadSum('usages', 'commission_amount');

        return $this->successResponse($referralCode, 'Referral code fetched successfully');
    }

    public function update(Request $request, ReferralCode $referralCode)
    {
        $validated = $request->validate([
            'partner_id' => 'sometimes|required|exists:partners,id',
            'code' => 'sometimes|required|string|max:50|unique:referral_codes,code,' . $referralCode->id,
            'status' => 'sometimes|required|in:draft,active,paused,expired',
            'start_date' => 'sometimes|required|date',
            'expiry_date' => 'sometimes|required|date|after_or_equal:start_date',
            'usage_limit' => 'sometimes|required|integer|min:0',
            'discount_type' => 'sometimes|required|in:percentage,fixed',
            'discount_value' => 'sometimes|required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'commission_type' => 'sometimes|required|in:percentage,fixed',
            'commission_value' => 'sometimes|required|numeric|min:0',
            'max_commission_amount' => 'nullable|numeric|min:0',
        ]);

        $referralCode->update($validated);
        $referralCode->load('partner');
        $referralCode->loadCount('usages');

        return $this->successResponse($referralCode, 'Referral code updated successfully');
    }

    public function destroy(ReferralCode $referralCode)
    {
        $referralCode->delete();
        return $this->successResponse(null, 'Referral code deleted successfully');
    }

    /**
     * Validate a referral code and return discount info.
     */
    public function validate(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $referralCode = ReferralCode::where('code', $validated['code'])->valid()->first();

        if (!$referralCode) {
            return $this->errorResponse('Invalid or expired referral code', 422);
        }

        $discount = $this->referralService->calculateDiscount($referralCode, $validated['subtotal']);
        $commission = $this->referralService->calculateCommission($referralCode, $validated['subtotal']);

        return $this->successResponse([
            'valid' => true,            'id' => $referralCode->id,
            'code' => $referralCode->code,
            'partner_name' => $referralCode->partner->name,
            'discount_type' => $referralCode->discount_type,
            'discount_value' => $referralCode->discount_value,
            'discount_amount' => $discount,
            'commission_amount' => $commission,
        ], 'Referral code is valid');
    }
}
