<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('partner_tag', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('name')->nullable();
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->unsignedBigInteger('partner_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_tag');
        Schema::dropIfExists('tags');
    }
};
