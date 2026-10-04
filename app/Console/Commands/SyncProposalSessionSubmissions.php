<?php

namespace App\Console\Commands;

use App\Services\ProposalSessionService;
use Illuminate\Console\Command;

class SyncProposalSessionSubmissions extends Command
{
    protected $signature = 'proposal-sessions:sync-submissions';

    protected $description = 'Close proposal submissions for sessions whose initial draft deadline has passed';

    public function handle(ProposalSessionService $sessionService): int
    {
        $closed = $sessionService->syncAllExpiredSubmissionStates();

        $this->info("Closed {$closed} expired proposal session(s).");

        return self::SUCCESS;
    }
}
