<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cod_whatsapp_messages', function (Blueprint $table) {
            // Who wrote the message: customer (inbound), ai (the
            // conversation agent), human (a manual reply from the
            // conversations inbox), system (confirmation / reminder /
            // notification templates). The inbox shows each kind
            // differently, and the agent uses it to build its history.
            $table->string('sender')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('sender');
        });
    }
};
