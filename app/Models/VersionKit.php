<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Table('version_kit')]
#[Fillable(['type_kit_id', 'numero', 'article_porteur_id'])]
class VersionKit extends Model
{
    protected function casts(): array
    {
        return [
            'verrouillee' => 'boolean',
            'verrouillee_le' => 'datetime',
        ];
    }

    public function typeKit(): BelongsTo
    {
        return $this->belongsTo(TypeKit::class);
    }

    public function porteur(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_porteur_id');
    }

    public function verrouilleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verrouillee_par');
    }

    /** RG03 : nomenclature (article + quantité). */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'composition', 'version_kit_id', 'article_id')
            ->withPivot('quantite');
    }
}
