<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Models\QrCodeScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class QrCodeController extends Controller
{
    /**
     * Get paginated QR codes with search.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 10);

        $qrCodes = QrCode::query()
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%")
                      ->orWhere('target_url', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($qrCodes, 'QR Codes fetched successfully');
    }

    /**
     * Store a new QR Code.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:qr_codes,slug|regex:/^[a-zA-Z0-9\-_]+$/',
            'target_url' => 'required|string',
            'description' => 'nullable|string',
        ], [
            'slug.regex' => 'The slug can only contain letters, numbers, dashes, and underscores.',
        ]);

        // Clean target URL
        $targetUrl = $validated['target_url'];
        // If it does not start with http:// or https:// and doesn't start with /
        if (!preg_match('~^(?:f|ht)tps?://~i', $targetUrl) && !str_starts_with($targetUrl, '/')) {
            $targetUrl = 'https://' . $targetUrl;
        }
        $validated['target_url'] = $targetUrl;

        $qrCode = QrCode::create($validated);

        return $this->successResponse($qrCode, 'QR Code created successfully', 201);
    }

    /**
     * Show a single QR Code.
     */
    public function show($id)
    {
        $qrCode = QrCode::findOrFail($id);
        return $this->successResponse($qrCode, 'QR Code fetched successfully');
    }

    /**
     * Update an existing QR Code.
     */
    public function update(Request $request, $id)
    {
        $qrCode = QrCode::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-_]+$/|unique:qr_codes,slug,' . $qrCode->id,
            'target_url' => 'required|string',
            'description' => 'nullable|string',
        ], [
            'slug.regex' => 'The slug can only contain letters, numbers, dashes, and underscores.',
        ]);

        // Clean target URL
        $targetUrl = $validated['target_url'];
        if (!preg_match('~^(?:f|ht)tps?://~i', $targetUrl) && !str_starts_with($targetUrl, '/')) {
            $targetUrl = 'https://' . $targetUrl;
        }
        $validated['target_url'] = $targetUrl;

        $qrCode->update($validated);

        return $this->successResponse($qrCode, 'QR Code updated successfully');
    }

    /**
     * Delete a QR Code.
     */
    public function destroy($id)
    {
        $qrCode = QrCode::findOrFail($id);
        $qrCode->delete();

        return $this->successResponse(null, 'QR Code deleted successfully');
    }

    /**
     * Public Redirection Endpoint.
     * Records scan and redirects to target URL.
     */
    public function redirect(Request $request, $slug)
    {
        $qrCode = QrCode::where('slug', $slug)->first();

        // Fallback destination if QR code not found
        $frontendUrl = config('app.frontend_url');

        if (!$qrCode) {
            return redirect()->away($frontendUrl);
        }

        // Anonymize IP if required, or store directly.
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');
        $referer = $request->header('referer') ?: $request->header('referrer');

        // Increment scan count on QrCode
        $qrCode->increment('scan_count');

        // Log the scan
        QrCodeScan::create([
            'qr_code_id' => $qrCode->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'referer' => $referer,
        ]);

        // Determine actual target URL
        $target = $qrCode->target_url;
        if (str_starts_with($target, '/')) {
            $target = rtrim($frontendUrl, '/') . $target;
        }

        // Add redirection parameters for tracking (utm sources) if not already present
        if (!str_contains($target, 'utm_source')) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $target .= $separator . 'utm_source=qrcode&utm_medium=offline&utm_campaign=' . urlencode($qrCode->slug);
        }

        // Perform 302 redirect so browser does not cache it and subsequent clicks are tracked
        return redirect()->away($target);
    }

    /**
     * Get Aggregated Analytics for a QR Code.
     */
    public function analytics(Request $request, $id)
    {
        $qrCode = QrCode::findOrFail($id);

        // Date range (default last 30 days)
        $days = (int) $request->input('days', 30);
        $startDate = Carbon::today()->subDays($days - 1);

        // 1. Total Scans (from cached count or scans count)
        $totalScans = $qrCode->scan_count;

        // 2. Unique Scans (based on unique IP addresses)
        $uniqueScans = QrCodeScan::where('qr_code_id', $qrCode->id)
            ->distinct('ip_address')
            ->count('ip_address');

        // 3. Scan timeline by day (recharts needs: { date: 'YYYY-MM-DD', scans: X })
        $rawTimeline = QrCodeScan::where('qr_code_id', $qrCode->id)
            ->whereDate('scanned_at', '>=', $startDate)
            ->select(DB::raw('DATE(scanned_at) as scan_date'), DB::raw('count(*) as count'))
            ->groupBy('scan_date')
            ->get()
            ->pluck('count', 'scan_date');

        $timeline = [];
        for ($i = 0; $i < $days; $i++) {
            $dateStr = $startDate->copy()->addDays($i)->format('Y-m-d');
            $timeline[] = [
                'date' => $dateStr,
                'scans' => $rawTimeline->get($dateStr, 0),
            ];
        }

        // 4. Device and Browser breakdowns
        $scans = QrCodeScan::where('qr_code_id', $qrCode->id)->get();
        
        $browsers = [];
        $devices = [];

        foreach ($scans as $scan) {
            $parsed = $this->parseUserAgent($scan->user_agent);
            
            $browser = $parsed['browser'];
            $device = $parsed['device'];

            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
            $devices[$device] = ($devices[$device] ?? 0) + 1;
        }

        // Format for frontend (charts)
        $browserData = [];
        foreach ($browsers as $name => $value) {
            $browserData[] = ['name' => $name, 'value' => $value];
        }
        // Sort browserData descending
        usort($browserData, function($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        $deviceData = [];
        foreach ($devices as $name => $value) {
            $deviceData[] = ['name' => $name, 'value' => $value];
        }

        // 5. Recent Scan Activity Feed (last 20 logs)
        $recentScans = QrCodeScan::where('qr_code_id', $qrCode->id)
            ->orderBy('scanned_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($scan) {
                $parsed = $this->parseUserAgent($scan->user_agent);
                return [
                    'id' => $scan->id,
                    'ip' => $scan->ip_address ? substr($scan->ip_address, 0, 8) . '...' : 'Unknown', // Mask for privacy
                    'browser' => $parsed['browser'],
                    'device' => $parsed['device'],
                    'referer' => $scan->referer ?: 'Direct / Scan',
                    'scanned_at' => $scan->scanned_at->toIso8601String(),
                ];
            });

        return $this->successResponse([
            'qr_code' => $qrCode,
            'summary' => [
                'total_scans' => $totalScans,
                'unique_scans' => $uniqueScans,
            ],
            'timeline' => $timeline,
            'browsers' => $browserData,
            'devices' => $deviceData,
            'recent_scans' => $recentScans,
        ], 'Analytics fetched successfully');
    }

    /**
     * A helper regex user-agent parser.
     */
    private function parseUserAgent($userAgent)
    {
        $browser = 'Unknown';
        $device = 'Desktop';

        if (!$userAgent) {
            return compact('browser', 'device');
        }

        // Device detection
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobile|Phone|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            $device = 'Mobile';
        } else {
            $device = 'Desktop';
        }

        // Browser detection
        if (preg_match('/Edge/i', $userAgent) || preg_match('/Edg/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Opera|OPR/i', $userAgent)) {
            $browser = 'Opera';
        } elseif (preg_match('/MSIE|Trident/i', $userAgent)) {
            $browser = 'IE';
        }

        return compact('browser', 'device');
    }
}
