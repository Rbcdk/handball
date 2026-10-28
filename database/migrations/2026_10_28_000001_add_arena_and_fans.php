<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->unsignedInteger('fans')->default(1300)->after('arena_capacity');
            $table->unsignedInteger('arena_expansion')->nullable()->after('fans');
            $table->unsignedTinyInteger('arena_build_left')->nullable()->after('arena_expansion');
        });
        Schema::table('fixtures', function (Blueprint $table) {
            $table->unsignedInteger('attendance')->nullable();
        });
        // Existing clubs start with as many fans as seats, so ticket income stays where it was.
        DB::table('clubs')->update(['fans' => DB::raw('arena_capacity')]);
    }

    public function down(): void
    {
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['fans', 'arena_expansion', 'arena_build_left']));
        Schema::table('fixtures', fn (Blueprint $t) => $t->dropColumn('attendance'));
    }
};
