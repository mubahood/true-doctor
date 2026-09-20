<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files belong to WORK, and work is not only a visit order.
 *
 * `order_attachments.order_id` pointed at `orders` and nothing else, so a lab
 * result PDF and an X-ray film — the two documents a hospital most needs to
 * keep — had nowhere to go. The lab and radiology worklists could take a
 * typed result and not the report the machine printed.
 *
 * One store, three owners. The table is CREATED rather than altered because
 * making `order_id` nullable on SQLite means rebuilding the table, which is
 * the cascade that once deleted every order and bill line in the system
 * (App\Support\SchemaKeys). A create, a copy and a drop costs nothing and
 * risks nothing on either engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attachments')) {
            return;
        }

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();

            // Not constrained: three tables can own one of these, and a key can
            // only point at one of them.
            $table->morphs('attachable');

            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime', 96)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'attachable_type', 'attachable_id'], 'attachments_owner_index');
        });

        if (! Schema::hasTable('order_attachments')) {
            return;
        }

        // Everything already filed keeps its uuid, its path and its uploader —
        // the download links in existing records go on working.
        DB::table('order_attachments')->orderBy('id')->chunkById(500, function ($rows) {
            DB::table('attachments')->insert(array_map(fn ($row) => [
                'uuid' => $row->uuid,
                'hospital_id' => $row->hospital_id,
                'attachable_type' => \App\Models\Order::class,
                'attachable_id' => $row->order_id,
                'file_path' => $row->file_path,
                'original_name' => $row->original_name,
                'mime' => $row->mime,
                'size' => $row->size,
                'uploaded_by' => $row->uploaded_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'deleted_at' => $row->deleted_at,
            ], $rows->all()));
        });

        Schema::dropIfExists('order_attachments');
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_attachments')) {
            Schema::create('order_attachments', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();

                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime', 96)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['hospital_id', 'order_id']);
            });
        }

        if (Schema::hasTable('attachments')) {
            // Only the ones an order can hold; a lab film has nowhere to go back to.
            DB::table('attachments')
                ->where('attachable_type', \App\Models\Order::class)
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    DB::table('order_attachments')->insert(array_map(fn ($row) => [
                        'uuid' => $row->uuid,
                        'hospital_id' => $row->hospital_id,
                        'order_id' => $row->attachable_id,
                        'file_path' => $row->file_path,
                        'original_name' => $row->original_name,
                        'mime' => $row->mime,
                        'size' => $row->size,
                        'uploaded_by' => $row->uploaded_by,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'deleted_at' => $row->deleted_at,
                    ], $rows->all()));
                });

            Schema::dropIfExists('attachments');
        }
    }
};
