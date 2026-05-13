<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvaluationCriterion;
use App\Models\EmployeeEvaluation;
use App\Models\EvaluationScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    /* ══════════════════════════════════════════════
     *  CRITERIA (company-level settings)
     * ══════════════════════════════════════════════ */

    /** GET /api/evaluation-criteria */
    public function criteriaIndex(): JsonResponse
    {
        return response()->json(
            EvaluationCriterion::orderBy('name')->get()
        );
    }

    /** POST /api/evaluation-criteria */
    public function criteriaStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'weight'  => 'required|numeric|min:0.01|max:100',
        ]);

        $criterion = EvaluationCriterion::create($validated);

        return response()->json($criterion, 201);
    }

    /** PUT /api/evaluation-criteria/{id} */
    public function criteriaUpdate(Request $request, EvaluationCriterion $criterion): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'name_ar'   => 'nullable|string|max:255',
            'weight'    => 'sometimes|numeric|min:0.01|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $criterion->update($validated);

        return response()->json($criterion);
    }

    /** DELETE /api/evaluation-criteria/{id} */
    public function criteriaDestroy(EvaluationCriterion $criterion): JsonResponse
    {
        $criterion->delete();
        return response()->json(['message' => 'Criterion deleted.']);
    }

    /* ══════════════════════════════════════════════
     *  EVALUATIONS
     * ══════════════════════════════════════════════ */

    /** GET /api/evaluations */
    public function index(Request $request): JsonResponse
    {
        $evaluations = EmployeeEvaluation::with(['employee:id,name,employee_number', 'evaluator:id,name'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('evaluation_date')
            ->paginate(50);

        return response()->json($evaluations);
    }

    /** GET /api/evaluations/{id} */
    public function show(EmployeeEvaluation $evaluation): JsonResponse
    {
        return response()->json(
            $evaluation->load([
                'employee:id,name,employee_number',
                'evaluator:id,name',
                'scores.criterion',
            ])
        );
    }

    /** POST /api/evaluations */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'evaluation_date' => 'required|date',
            'period_from'     => 'required|date',
            'period_to'       => 'required|date|after_or_equal:period_from',
            'notes'           => 'nullable|string',
            'scores'          => 'required|array|min:1',
            'scores.*.criterion_id' => 'required|exists:evaluation_criteria,id',
            'scores.*.score'        => 'required|numeric|min:0',
            'scores.*.notes'        => 'nullable|string',
        ]);

        $validated['evaluator_id'] = $request->user()->id;
        $validated['status'] = 'submitted';

        $evaluation = EmployeeEvaluation::create(
            collect($validated)->except('scores')->toArray()
        );

        // Save individual criterion scores
        foreach ($validated['scores'] as $scoreData) {
            $evaluation->scores()->create($scoreData);
        }

        // Calculate and save overall score
        $evaluation->overall_score = $evaluation->calculateOverallScore();
        $evaluation->save();

        return response()->json(
            $evaluation->load(['scores.criterion', 'employee:id,name', 'evaluator:id,name']),
            201
        );
    }

    /** PUT /api/evaluations/{id} */
    public function update(Request $request, EmployeeEvaluation $evaluation): JsonResponse
    {
        if ($evaluation->status === 'approved') {
            return response()->json(['message' => 'Cannot edit an approved evaluation.'], 422);
        }

        $validated = $request->validate([
            'evaluation_date' => 'sometimes|date',
            'period_from'     => 'sometimes|date',
            'period_to'       => 'sometimes|date',
            'notes'           => 'nullable|string',
            'status'          => 'sometimes|in:draft,submitted,approved',
            'scores'          => 'sometimes|array|min:1',
            'scores.*.criterion_id' => 'required_with:scores|exists:evaluation_criteria,id',
            'scores.*.score'        => 'required_with:scores|numeric|min:0',
            'scores.*.notes'        => 'nullable|string',
        ]);

        $evaluation->update(
            collect($validated)->except('scores')->toArray()
        );

        // Replace scores if provided
        if (isset($validated['scores'])) {
            $evaluation->scores()->delete();
            foreach ($validated['scores'] as $scoreData) {
                $evaluation->scores()->create($scoreData);
            }
            $evaluation->overall_score = $evaluation->calculateOverallScore();
            $evaluation->save();
        }

        return response()->json(
            $evaluation->load(['scores.criterion', 'employee:id,name', 'evaluator:id,name'])
        );
    }

    /** DELETE /api/evaluations/{id} */
    public function destroy(EmployeeEvaluation $evaluation): JsonResponse
    {
        $evaluation->scores()->delete();
        $evaluation->delete();
        return response()->json(['message' => 'Evaluation deleted.']);
    }
}
