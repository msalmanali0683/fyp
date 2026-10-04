<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Session Workflow Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 18px; margin-bottom: 6px; color: #1e3a8a; }
        h3 { font-size: 12px; margin: 10px 0 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        .project { page-break-inside: avoid; border: 1px solid #ddd; padding: 10px; margin-bottom: 14px; border-radius: 4px; }
        .project-meta { margin: 6px 0 10px; }
        .project-meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>{{ $sessionName }} — Workflow Report</h1>
    <div class="meta">
        Session code: {{ $sessionCode }} · Program: {{ $programName }}<br>
        Lifecycle snapshot: {{ $lifecycleLabel }} · Generated: {{ $generatedAt }}
    </div>

    @forelse ($projects as $project)
        <div class="project">
            <h2>{{ $loop->iteration }}. {{ $project['title'] }}</h2>
            <div class="project-meta">
                <div><strong>Leader:</strong> {{ $project['leader'] }}</div>
                <div><strong>Team:</strong> {{ $project['members'] ?: '—' }}</div>
                <div><strong>Supervisor:</strong> {{ $project['supervisor'] ?: 'Not assigned' }}</div>
                <div><strong>Status at snapshot:</strong> {{ $project['status_label'] }}</div>
                <div><strong>Current phase:</strong> {{ $project['current_phase_label'] }}</div>
                <div><strong>Proposal evaluator marks:</strong> {{ $project['proposal_evaluator_marks'] }}</div>
                <div><strong>Phase 1 evaluator marks:</strong> {{ $project['phase_1_evaluator_marks'] }}</div>
                <div><strong>Phase 2 evaluator marks:</strong> {{ $project['phase_2_evaluator_marks'] }}</div>
            </div>

            <h3>Workflow History</h3>
            @if (count($project['logs']))
                <table>
                    <thead>
                        <tr>
                            <th style="width: 16%">Date</th>
                            <th style="width: 12%">Stage</th>
                            <th style="width: 14%">FYP Phase</th>
                            <th style="width: 14%">Actor</th>
                            <th>Action</th>
                            <th style="width: 18%">Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($project['logs'] as $log)
                            <tr>
                                <td>{{ $log['date'] }}</td>
                                <td>{{ $log['stage'] }}</td>
                                <td>{{ $log['fyp_phase'] }}</td>
                                <td>{{ $log['actor'] }}</td>
                                <td>{{ $log['action'] }}</td>
                                <td>{{ $log['comments'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">No workflow entries recorded for this project.</p>
            @endif
        </div>
    @empty
        <p class="muted">No projects found for this session snapshot.</p>
    @endforelse
</body>
</html>
