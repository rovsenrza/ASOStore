<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // A customer-imported app (IPA from Files or a link) belongs to the customer who
            // imported it and is HIDDEN from the public catalog; only its owner can install it.
            $table->foreignId('imported_by_user_id')->nullable()->after('is_storefront')->constrained('users')->cascadeOnDelete();
            $table->index('imported_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_by_user_id');
        });
    }
};
