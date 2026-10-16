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

    /** RG14 : produits associés, sans effet sur la nomenclature. */
    public function associes(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_associe', 'article_id', 'article_associe_id');
    }
}
