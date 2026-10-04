<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Query: {{ $subject }}</title>
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
            font-size: 20px;
            margin: 0 0 6px;
            color: #312e81;
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

        .message {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 10px;
        }

        .message-meta {
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .message-meta strong {
            color: #111827;
        }

        .message-text {
            white-space: pre-wrap;
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            color: #6b7280;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="header">
            <h1>Project Query Report</h1>
            <div class="subtitle">{{ $projectTitle }} · {{ $programName }}</div>
            <div class="badge">Status: {{ $status }}</div>
        </div>

        <div class="section">
            <div class="section-title">Query Details</div>
            <table class="meta-grid">
                <tr>
                    <td>Subject</td>
                    <td><strong>{{ $subject }}</strong></td>
                </tr>
                <tr>
                    <td>Raised By</td>
                    <td>{{ $raiserName }} ({{ $raiserRole }})</td>
                </tr>
                <tr>
                    <td>Raised On</td>
                    <td>{{ $createdAt }}</td>
                </tr>
                @if ($closedAt)
                    <tr>
                        <td>Closed On</td>
                        <td>{{ $closedAt }} @if ($closedBy) by {{ $closedBy }} @endif</td>
                    </tr>
                @endif
            </table>
        </div>

        <div class="section">
            <div class="section-title">Conversation</div>
            @forelse ($messages as $message)
                <div class="message">
                    <div class="message-meta">
                        <strong>{{ $message['author'] }}</strong> ({{ $message['role'] }}) · {{ $message['timestamp'] }}
                    </div>
                    <div class="message-text">{{ $message['text'] }}</div>
                </div>
            @empty
                <p>No messages recorded.</p>
            @endforelse
        </div>

        <div class="footer">
            Generated on {{ $generatedAt }}.
        </div>
    </div>
</body>
</html>
