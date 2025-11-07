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
        Schema::table('users', function (Blueprint $table) {
            $table->string('brs_type',25)->nullable()->after('user_type'); 
            $table->integer('contact')->nullable()->after('email');
            $table->string('bankname',25)->nullable()->after('contact');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('brs_type');
            $table->dropColumn('contact');
            $table->dropColumn('bankname');

        });
    }
};
