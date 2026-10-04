<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supervisor_change_requests')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $existingKeys = collect(DB::select('SHOW CREATE TABLE supervisor_change_requests'))
            ->first()
            ->{'Create Table'};

        Schema::table('supervisor_change_requests', function (Blueprint $table) use ($existingKeys) {
            if (! str_contains($existingKeys, 'scr_current_responder_fk')) {
                $table->foreign('current_supervisor_responded_by', 'scr_current_responder_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }

            if (! str_contains($existingKeys, 'scr_new_responder_fk')) {
                $table->foreign('new_supervisor_responded_by', 'scr_new_responder_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }

            if (! str_contains($existingKeys, 'scr_authority_decider_fk')) {
                $table->foreign('authority_decided_by', 'scr_authority_decider_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supervisor_change_requests')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('supervisor_change_requests', function (Blueprint $table) {
            $table->dropForeign('scr_current_responder_fk');
            $table->dropForeign('scr_new_responder_fk');
            $table->dropForeign('scr_authority_decider_fk');
        });
    }
};
