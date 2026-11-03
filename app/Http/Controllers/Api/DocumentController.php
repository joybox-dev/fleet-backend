<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «المستندات»: a name the user writes and a file (client, 2026-10-27), for an employee, a vehicle or
 * the company. Whoever holds the documents permission works on all of them; an employee's or a
 * vehicle's own documents also open to whoever may see — or edit — that employee or vehicle, so the
 * tab on the profile keeps working for the roles that already used it.
 */
class DocumentController extends Controller
{
    /** The section an owner's documents also belong to. */
    private const OWNER_MODULE = ['employee' => 'employees', 'vehicle' => 'vehicles'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'owner_type' => ['nullable', Rule::in(Document::OWNER_TYPES)],
            'owner_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['expired', 'due', 'valid', 'no_expiry'])],
            'search' => ['nullable', 'string', 'max:120'],
            'with_owners' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        $types = array_values(array_filter(Document::OWNER_TYPES, fn ($type) => self::may($user, $type, 'view')));
        if (! empty($filters['owner_type'])) {
            $types = array_values(array_intersect($types, [$filters['owner_type']]));
        }
        if ($types === []) {
            return response()->json(['message' => 'غير مصرح لك باستعراض المستندات.'], 403);
        }

        $rows = Document::with(['employee:id,name,name_ar,employee_number', 'vehicle:id,plate_number'])
            ->whereIn('owner_type', $types)
            ->when(! empty($filters['owner_id']), fn ($q) => $q->where('owner_id', $filters['owner_id']))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderByDesc('id')
            ->get()
            ->map(fn (Document $d) => self::row($d));

        if (! empty($filters['status'])) {
            $rows = $rows->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower($filters['search']);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', [
                $r['title'], $r['document_number'], $r['notes'], $r['owner_name'], $r['owner_ref'],
            ])), $needle));
        }

        $response = ['data' => $rows->values()];

        // The pickers of the add form, for a login that may add documents but not open those screens.
        if (! empty($filters['with_owners'])) {
            $response['owners'] = [
                'employees' => self::may($user, 'employee', 'create')
                    ? Employee::whereNotIn('status', ['terminated'])->orderBy('name')->get(['id', 'name', 'name_ar', 'employee_number'])
                    : [],
                'vehicles' => self::may($user, 'vehicle', 'create')
                    ? Vehicle::orderBy('plate_number')->get(['id', 'plate_number'])
                    : [],
            ];
            $response['can_create'] = array_values(array_filter(Document::OWNER_TYPES, fn ($type) => self::may($user, $type, 'create')));
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        $type = (string) $request->input('owner_type');
        if (! self::may($request->user(), $type, 'create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة مستند هنا.'], 403);
        }

        $data = $request->validate(self::rules($type, false), self::messages());
        $data['owner_id'] = $type === 'company' ? null : (int) $data['owner_id'];
        $data['created_by'] = $request->user()->id;

        $document = Document::create($data);

        return response()->json(['data' => self::row($document->load(['employee', 'vehicle']))], 201);
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        if (! self::may($request->user(), $document->owner_type, 'edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل هذا المستند.'], 403);
        }

        // Who it belongs to stays as recorded; only the document itself is edited.
        $data = $request->validate(self::rules($document->owner_type, true), self::messages());
        unset($data['owner_type'], $data['owner_id']);
        $document->update($data);

        return response()->json(['data' => self::row($document->fresh(['employee', 'vehicle']))]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        if (! self::may($request->user(), $document->owner_type, 'delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف هذا المستند.'], 403);
        }
        $document->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    /**
     * The documents permission, or — for an employee's or a vehicle's — the same on its own
     * section, where adding or removing a document is editing the record.
     */
    public static function may(?User $user, string $ownerType, string $action): bool
    {
        if (! $user || ! in_array($ownerType, Document::OWNER_TYPES, true)) {
            return false;
        }
        if ($user->can("documents.{$action}")) {
            return true;
        }
        $module = self::OWNER_MODULE[$ownerType] ?? null;

        return $module !== null && $user->can($module.'.'.($action === 'view' ? 'view' : 'edit'));
    }

    /** @return array<string, mixed> */
    private static function rules(string $type, bool $partial): array
    {
        $companyId = (int) app('current_company_id');
        $req = $partial ? 'sometimes' : 'required';
        $table = $type === 'vehicle' ? 'vehicles' : 'employees';

        return [
            'owner_type' => [$req, Rule::in(Document::OWNER_TYPES)],
            'owner_id' => $type === 'company'
                ? ['nullable']
                : [$req, 'integer', Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'title' => [$req, 'string', 'max:150'],
            'file_path' => [$req, 'string', 'max:500'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'title.required' => 'اكتب اسم المستند.',
            'file_path.required' => 'أرفق ملف المستند.',
            'owner_id.required' => 'اختر لمن هذا المستند.',
            'owner_id.exists' => 'الموظف أو المركبة غير موجودة.',
            'expiry_date.after_or_equal' => 'تاريخ الانتهاء قبل تاريخ الإصدار.',
        ];
    }

    /** @return array<string, mixed> */
    private static function row(Document $d): array
    {
        $owner = match ($d->owner_type) {
            'employee' => [$d->employee?->name, $d->employee?->employee_number, $d->employee?->name_ar],
            'vehicle' => [$d->vehicle?->plate_number, null, null],
            default => [null, null, null],
        };

        return [
            'id' => $d->id,
            'owner_type' => $d->owner_type,
            'owner_id' => $d->owner_id,
            'owner_name' => $owner[0],
            'owner_name_ar' => $owner[2],
            'owner_ref' => $owner[1],
            'title' => $d->title,
            'document_number' => $d->document_number,
            'file_path' => $d->file_path,
            'issue_date' => $d->issue_date?->toDateString(),
            'expiry_date' => $d->expiry_date?->toDateString(),
            'notes' => $d->notes,
            'status' => $d->status,
            'days_left' => $d->days_left,
        ];
    }
}
