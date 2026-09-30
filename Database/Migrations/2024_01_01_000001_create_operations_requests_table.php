<?php

use App\Contracts\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class CreateOperationsRequestsTable extends Migration
{
    public function up()
    {
        // Verificar si la tabla ya existe antes de crearla
        if (Schema::hasTable('vms_open_ops_requests')) {
            return;
        }
        
        Schema::create('vms_open_ops_requests', function (Blueprint $table) {
            $table->id();
            
            // Type of operation
            $table->enum('operation_type', ['jumpseat', 'ferry']);
            
            // User information
            $table->unsignedBigInteger('user_id');
            
            // Jumpseat specific fields
            $table->string('from_airport_id', 5)->nullable();
            $table->string('to_airport_id', 5)->nullable();
            
            // Ferry specific fields
            $table->unsignedBigInteger('aircraft_id')->nullable();
            $table->unsignedBigInteger('subfleet_id')->nullable();
            $table->decimal('aircraft_distance', 10, 2)->nullable();
            
            // Common fields
            $table->decimal('distance', 10, 2);
            $table->integer('cost')->unsigned();
            $table->text('reason')->nullable();
            $table->tinyInteger('type')->default(0)->comment('0: request, 1: immediate');
            $table->tinyInteger('status')->default(0)->comment('0: pending, 1: approved, 2: rejected');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            // Índices (sin foreign keys por ahora para evitar errores)
            $table->index(['user_id', 'operation_type', 'status']);
            $table->index('aircraft_id');
            $table->index('subfleet_id');
            $table->index('from_airport_id');
            $table->index('to_airport_id');
        });
        
        // Agregar las foreign keys después de crear la tabla
        // Esto ayuda a evitar errores de orden de migración
        Schema::table('vms_open_ops_requests', function (Blueprint $table) {
            // Verificar que la tabla users existe antes de agregar la foreign key
            if (Schema::hasTable('users')) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            }
            
            // Verificar que la tabla aircraft existe
            if (Schema::hasTable('aircraft')) {
                $table->foreign('aircraft_id')
                    ->references('id')
                    ->on('aircraft')
                    ->onDelete('set null');
            }
            
            // Verificar que la tabla subfleets existe
            if (Schema::hasTable('subfleets')) {
                $table->foreign('subfleet_id')
                    ->references('id')
                    ->on('subfleets')
                    ->onDelete('set null');
            }
            
            // Verificar que la tabla users existe para approved_by
            if (Schema::hasTable('users')) {
                $table->foreign('approved_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('vms_open_ops_requests');
    }
}