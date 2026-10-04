<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $phaseLabel }} Certificate</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 24px;
        }

        .frame {
            border: 3px double #4338ca;
            padding: 28px 24px;
            min-height: 980px;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .header h1 {
            font-size: 24px;
            margin: 0 0 6px;
            color: #312e81;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .header h2 {
            font-size: 16px;
            margin: 0 0 10px;
            color: #4338ca;
        }

        .header .subtitle {
            color: #6b7280;
            font-size: 11px;
        }

        .badge {
            display: inline-block;
            background: #eef2ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 10px;
            font-weight: 700;
            margin-top: 8px;
        }

        .section {
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e3a8a;
            border-bottom: 1px solid #dbeafe;
            padding-bottom: 4px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .meta-grid {
            width: 100%;
        }

        .meta-grid td {
            padding: 4px 0;
            vertical-align: top;
        }

        .meta-grid td:first-child {
            width: 28%;
            color: #6b7280;
            font-weight: 600;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        table.data th,
        table.data td {
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            text-align: left;
            vertical-align: top;
        }

        table.data th {
            background: #f3f4f6;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px 14px;
            margin-top: 8px;
        }

        .summary-box strong {
            color: #111827;
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            color: #6b7280;
            font-size: 10px;
        }

        .signature-row {
            margin-top: 36px;
            width: 100%;
        }

        .signature-row td {
            width: 50%;
            text-align: center;
            vertical-align: bottom;
            padding-top: 40px;
        }

        .signature-line {
            border-top: 1px solid #9ca3af;
            width: 70%;
            margin: 0 auto 6px;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="header">
            <h1>{{ $certificateTitle }}</h1>
            <h2>{{ $phaseLabel }}</h2>
            <div class="subtitle">{{ $programName }} · {{ $sessionName }} ({{ $sessionCode }})</div>
            <div class="badge">Certificate Ref: {{ $certificateRef }}</div>
        </div>

        <div class="section">
            <div class="section-title">Project Details</div>
            <table class="meta-grid">
                <tr>
                    <td>Project Title</td>
                    <td><strong>{{ $projectTitle }}</strong></td>
                </tr>
                <tr>
                    <td>Group Leader</td>
                    <td>{{ $leader }}</td>
                </tr>
                <tr>
                    <td>Team Members</td>
                    <td>{{ $teamMembers ?: '—' }}</td>
                </tr>
                <tr>
                    <td>Supervisor</td>
                    <td>{{ $supervisor }}</td>
                </tr>
                <tr>
                    <td>Approved On</td>
                    <td>{{ $approvedAt }}</td>
                </tr>
                <tr>
                    <td>Approved By</td>
                    <td>{{ $approvedBy }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Evaluator Marks</div>
            @if (count($evaluatorReviews))
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width: 34%">Evaluator</th>
                            <th style="width: 14%">Marks</th>
                            <th style="width: 18%">Decision</th>
                            <th>Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($evaluatorReviews as $review)
                            <tr>
                                <td>{{ $review['evaluator'] }}</td>
                                <td>{{ $review['marks'] }}/{{ $review['maxMarks'] ?? '?' }}</td>
                                <td>{{ $review['decision'] ?: '—' }}</td>
                                <td>{{ $review['comments'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($averageMarks !== null)
                    <div class="summary-box">
                        <strong>Average Marks:</strong> {{ $averageMarks }}/{{ $averageMaxMarks ?? '?' }}
                    </div>
                @endif
            @else
                <p>No evaluator marks were recorded for this phase.</p>
            @endif
        </div>

        <div class="section">
            <div class="section-title">{{ $phaseLabel }} Workflow History</div>
            @if (count($workflowLogs))
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width: 16%">Date</th>
                            <th style="width: 18%">Stage</th>
                            <th style="width: 16%">Actor</th>
                            <th>Action</th>
                            <th style="width: 22%">Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workflowLogs as $log)
                            <tr>
                                <td>{{ $log['date'] }}</td>
                                <td>{{ $log['stage'] }}</td>
                                <td>{{ $log['actor'] }}</td>
                                <td>{{ $log['action'] }}</td>
                                <td>{{ $log['comments'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>No workflow entries were recorded for this phase.</p>
            @endif
        </div>

        <table class="signature-row">
            <tr>
                <td>
                    <div class="signature-line"></div>
                    Committee Head
                </td>
                <td>
                    <div class="signature-line"></div>
                    FYP Office
                </td>
            </tr>
        </table>

        <div class="footer">
            Generated on {{ $generatedAt }}. This certificate covers the {{ $phaseLabel }} phase only.
        </div>
    </div>
</body>
</html>
