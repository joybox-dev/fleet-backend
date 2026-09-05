<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustodyType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CustodyTypeController extends Controller
{
    /**
     * The name only has to be unique inside the company. Unscoped, it refused any name another
     * company had already taken.
     */
    private function uniqueNameInCompany(?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('custody_types', 'name')
            ->where('company_id', app('current_company_id'));

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }

    /** The settings screen is Arabic throughout; the framework's default message is not. */
    private const NAME_MESSAGES = [
        'name.required' => 'اسم نوع العهدة مطلوب.',
        'name.unique' => 'يوجد نوع عهدة بهذا الاسم في شركتك.',
        'name.max' => 'اسم نوع العهدة طويل جداً.',
    ];

    public function index(): JsonResponse
    {
        return response()->json(CustodyType::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', $this->uniqueNameInCompany()],
            'icon' => 'nullable|string|max:10',
        ], self::NAME_MESSAGES);

        $type = CustodyType::create($validated);

        return response()->json($type, 201);
    }

    public function update(Request $request, CustodyType $custodyType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', $this->uniqueNameInCompany($custodyType->id)],
            'icon' => 'nullable|string|max:10',
        ], self::NAME_MESSAGES);

        $custodyType->update($validated);

        return response()->json($custodyType);
    }

    public function deletionCheck(CustodyType $custodyType): JsonResponse
    {
        $blocks = $custodyType->getDeletionBlocks();

        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(CustodyType $custodyType): JsonResponse
    {
        $blocks = $custodyType->getDeletionBlocks();
        if (! empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف نوع العهدة لوجود ارتباطات نشطة.',
                'errors' => $blocks,
            ], 422);
        }

        $custodyType->delete();

        return response()->json(['message' => 'تم حذف النوع بنجاح.']);
    }
}
