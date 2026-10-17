<?php

namespace App\Http\Controllers\Api;

use App\Application\Freelancing\Contracts\AssistantInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FreelanceAssistantController extends Controller
{
    public function __construct(protected AssistantInterface $assistant) {}

    public function reply(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'context' => 'nullable|array',
        ]);
        return $this->successResponse([
            'reply' => $this->assistant->reply($data['message'], $data['context'] ?? []),
            'generated_by' => 'illuvision-assistant',
        ], 'Assistant response generated successfully.');
    }
}
