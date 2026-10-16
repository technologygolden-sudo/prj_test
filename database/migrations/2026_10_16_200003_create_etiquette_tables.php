<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquettes : imprimantes, modèles et journal des impressions (RG12, dossier v2 section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imprimante', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('adresse', 255); // IP:port (ZPL, port 9100) ou nom de file système (PDF)
            $table->string('modele', 64);
            $table->unsignedSmallInteger('dpi')->default(203);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE imprimante ADD CONSTRAINT imprimante_dpi CHECK (dpi IN (203, 300, 600))');

        Schema::create('modele_etiquette', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('cible', 8); // article | lot | kit
            $table->string('format', 4); // ZPL | PDF
            $table->decimal('largeur_mm', 5, 1);
            $table->decimal('hauteur_mm', 5, 1);
            $table->unsignedSmallInteger('dpi')->default(203);
            $table->text('gabarit');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE modele_etiquette
                ADD CONSTRAINT modele_etiquette_cible CHECK (cible IN ('article', 'lot', 'kit')),
                ADD CONSTRAINT modele_etiquette_format CHECK (format IN ('ZPL', 'PDF')),
                ADD CONSTRAINT modele_etiquette_dimensions CHECK (largeur_mm > 0 AND hauteur_mm > 0)
        SQL);

        Schema::create('impression_etiquette', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('est_reimpression')->default(false);
            $table->text('motif')->nullable();
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->foreignId('modele_id')->constrained('modele_etiquette');
            $table->foreignId('imprimante_id')->constrained('imprimante');
            $table->foreignId('utilisateur_id')->constrained('users');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE impression_etiquette
                ADD CONSTRAINT impression_etiquette_cible CHECK (num_nonnulls(unite_id, kit_id) = 1),
                ADD CONSTRAINT impression_etiquette_copies CHECK (copies BETWEEN 1 AND 100),
                ADD CONSTRAINT impression_etiquette_motif CHECK (NOT est_reimpression OR motif IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('impression_etiquette');
        Schema::dropIfExists('modele_etiquette');
        Schema::dropIfExists('imprimante');
    }
};
