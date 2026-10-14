<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->string('visibility', 16)->default('team');
            $table->string('accent', 16)->default('blue');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'slug']);
            $table->unique(['team_id', 'id'], 'project_boards_team_id_unique');
            $table->index(['team_id', 'archived_at']);
        });

        Schema::create('project_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->foreignId('user_id');
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
            $table->foreign(['team_id', 'board_id'], 'project_board_members_board_fk')
                ->references(['team_id', 'id'])->on('project_boards')->cascadeOnDelete();
            $table->foreign(['team_id', 'user_id'], 'project_board_members_team_user_fk')
                ->references(['team_id', 'user_id'])->on('team_user')->cascadeOnDelete();
        });

        Schema::create('project_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->string('name');
            $table->unsignedInteger('position');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('wip_limit')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'board_id', 'id'], 'project_columns_team_board_id_unique');
            $table->index(['team_id', 'board_id', 'position']);
            $table->foreign(['team_id', 'board_id'], 'project_columns_board_fk')
                ->references(['team_id', 'id'])->on('project_boards')->cascadeOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Keep the existing task ID, customer/deal links, due date and
            // completion time. A second card table would make CRM actions
            // diverge from the project board and leave old tasks stranded.
            $table->foreignId('project_board_id')->nullable();
            $table->foreignId('project_column_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->unique(['team_id', 'project_board_id', 'id'], 'tasks_team_project_board_id_unique');
            $table->index(['team_id', 'project_board_id', 'archived_at', 'project_column_id', 'position'], 'tasks_project_board_order_idx');
        });

        // Existing CRM reminders become cards on one board per team. Raw
        // updates include soft-deleted rows; skipping them would leave rows
        // that fail the new board/column invariant on restore.
        foreach (DB::table('tasks')->distinct()->pluck('team_id') as $teamId) {
            $now = now();
            $boardId = DB::table('project_boards')->insertGetId([
                'team_id' => $teamId, 'created_by' => null, 'name' => 'Задачи',
                'slug' => '_inbox', 'visibility' => 'team', 'accent' => 'blue',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $todoId = DB::table('project_columns')->insertGetId([
                'team_id' => $teamId, 'board_id' => $boardId, 'name' => 'К выполнению',
                'position' => 0, 'is_done' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('project_columns')->insert([
                'team_id' => $teamId, 'board_id' => $boardId, 'name' => 'В работе',
                'position' => 1, 'is_done' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $doneId = DB::table('project_columns')->insertGetId([
                'team_id' => $teamId, 'board_id' => $boardId, 'name' => 'Готово',
                'position' => 2, 'is_done' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('tasks')->where('team_id', $teamId)->whereNull('done_at')->update([
                'project_board_id' => $boardId, 'project_column_id' => $todoId,
                'position' => DB::raw('id'),
            ]);
            DB::table('tasks')->where('team_id', $teamId)->whereNotNull('done_at')->update([
                'project_board_id' => $boardId, 'project_column_id' => $doneId,
                'position' => DB::raw('id'),
            ]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            // The column key contains board + team, so a card cannot be
            // moved into a column on another board even by raw SQL.
            $table->foreign(['team_id', 'project_board_id', 'project_column_id'], 'tasks_project_column_fk')
                ->references(['team_id', 'board_id', 'id'])->on('project_columns')->restrictOnDelete();
        });

        Schema::create('project_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->foreignId('task_id');
            $table->foreignId('user_id');
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['team_id', 'user_id']);
            $table->foreign(['team_id', 'board_id', 'task_id'], 'project_assignees_task_fk')
                ->references(['team_id', 'project_board_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign(['team_id', 'user_id'], 'project_assignees_member_fk')
                ->references(['team_id', 'user_id'])->on('team_user')->cascadeOnDelete();
        });

        Schema::create('project_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->string('name', 80);
            $table->string('color', 16);
            $table->timestamps();
            $table->unique(['team_id', 'board_id', 'id'], 'project_labels_team_board_id_unique');
            $table->foreign(['team_id', 'board_id'], 'project_labels_board_fk')
                ->references(['team_id', 'id'])->on('project_boards')->cascadeOnDelete();
        });

        Schema::create('project_task_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->foreignId('task_id');
            $table->foreignId('label_id');
            $table->unique(['task_id', 'label_id']);
            $table->foreign(['team_id', 'board_id', 'task_id'], 'project_task_labels_task_fk')
                ->references(['team_id', 'project_board_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign(['team_id', 'board_id', 'label_id'], 'project_task_labels_label_fk')
                ->references(['team_id', 'board_id', 'id'])->on('project_labels')->cascadeOnDelete();
        });

        Schema::create('project_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->foreignId('task_id');
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'position']);
            $table->foreign(['team_id', 'board_id', 'task_id'], 'project_checklist_task_fk')
                ->references(['team_id', 'project_board_id', 'id'])->on('tasks')->cascadeOnDelete();
        });

        Schema::create('project_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('board_id');
            $table->foreignId('task_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->text('body')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'created_at']);
            $table->foreign(['team_id', 'board_id', 'task_id'], 'project_events_task_fk')
                ->references(['team_id', 'project_board_id', 'id'])->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['project_task_events', 'project_checklist_items', 'project_task_labels', 'project_labels', 'project_task_assignees'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign('tasks_project_column_fk');
            $table->dropUnique('tasks_team_project_board_id_unique');
            $table->dropIndex('tasks_project_board_order_idx');
            $table->dropColumn(['project_board_id', 'project_column_id', 'description', 'position', 'archived_at']);
        });
        Schema::dropIfExists('project_columns');
        Schema::dropIfExists('project_board_members');
        Schema::dropIfExists('project_boards');
    }
};
