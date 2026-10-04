<?php

namespace App\Services;

use App\Models\ProjectEvaluator;
use App\Models\User;
use Illuminate\Support\Collection;

class EvaluatorOverviewService
{
    public function emptyPhaseStats(): array
    {
        return ['pending' => 0, 'complete' => 0, 'total' => 0];
    }

    public function buildStatsMap(Collection $evaluators): array
    {
        $evaluatorIds = $evaluators->pluck('id');

        if ($evaluatorIds->isEmpty()) {
            return [];
        }

        $rows = ProjectEvaluator::query()
            ->selectRaw('project_evaluators.evaluator_id')
            ->selectRaw('projects.current_phase as phase')
            ->selectRaw('SUM(CASE WHEN evaluator_reviews.id IS NULL THEN 1 ELSE 0 END) as pending_count')
            ->selectRaw('SUM(CASE WHEN evaluator_reviews.id IS NOT NULL THEN 1 ELSE 0 END) as complete_count')
            ->join('projects', 'projects.id', '=', 'project_evaluators.project_id')
            ->leftJoin('evaluator_reviews', function ($join) {
                $join->on('evaluator_reviews.project_id', '=', 'project_evaluators.project_id')
                    ->on('evaluator_reviews.evaluator_id', '=', 'project_evaluators.evaluator_id')
                    ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase');
            })
            ->whereIn('project_evaluators.evaluator_id', $evaluatorIds)
            ->where('projects.status', 'active')
            ->whereIn('projects.current_phase', ['proposal', 'phase_1', 'phase_2'])
            ->groupBy('project_evaluators.evaluator_id', 'projects.current_phase')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            if (! in_array($row->phase, ['proposal', 'phase_1', 'phase_2'], true)) {
                continue;
            }

            $map[$row->evaluator_id][$row->phase] = [
                'pending' => (int) $row->pending_count,
                'complete' => (int) $row->complete_count,
                'total' => (int) $row->pending_count + (int) $row->complete_count,
            ];
        }

        return $map;
    }

    public function statsFor(User $evaluator, array $map): array
    {
        $evaluatorStats = $map[$evaluator->id] ?? [];
        $proposal = $evaluatorStats['proposal'] ?? $this->emptyPhaseStats();
        $phase1 = $evaluatorStats['phase_1'] ?? $this->emptyPhaseStats();
        $phase2 = $evaluatorStats['phase_2'] ?? $this->emptyPhaseStats();

        return [
            'proposal' => $proposal,
            'phase_1' => $phase1,
            'phase_2' => $phase2,
            'total_pending' => $proposal['pending'] + $phase1['pending'] + $phase2['pending'],
            'total_complete' => $proposal['complete'] + $phase1['complete'] + $phase2['complete'],
            'total_assignments' => $proposal['total'] + $phase1['total'] + $phase2['total'],
        ];
    }
}
