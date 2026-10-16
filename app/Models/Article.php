<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Table('article')]
#[Fillable(['reference', 'nom', 'type', 'est_serialise', 'categorie_id', 'fabricant_id'])]
class Article extends Model
{
    protected function casts(): array
    {
        return ['est_serialise' => 'boolean'];
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function fabricant(): BelongsTo
    {
        return $this->belongsTo(Fabricant::class);
    }

    /** Versions de kit dont l'article est le porteur de série. */
    public function porteurDe(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VersionKit::class, 'article_porteur_id');
    }

    /** Versions de kit dont l'article fait partie (un article peut entrer dans plusieurs kits). */
    public function kits(): BelongsToMany
    {
        return $this->belongsToMany(VersionKit::class, 'composition', 'article_id', 'version_kit_id');
    }

    /** RG14 : produits associés, sans effet sur la nomenclature. */
    public function associes(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_associe', 'article_id', 'article_associe_id');
    }
}
