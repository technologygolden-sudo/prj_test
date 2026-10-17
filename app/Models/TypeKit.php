<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('type_kit')]
#[Fillable(['reference', 'partno', 'nom', 'categorie_id', 'fabricant_id', 'cout_unitaire'])]
class TypeKit extends Model
{
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function fabricant(): BelongsTo
    {
        return $this->belongsTo(Fabricant::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VersionKit::class)->orderByDesc('numero');
    }

    /** RG10 : la version active est la plus récente des versions verrouillées. */
    public function versionActive(): HasOne
    {
        return $this->hasOne(VersionKit::class)->ofMany(['numero' => 'max'], fn ($q) => $q->where('verrouillee', true));
    }

    /** Version en cours de rédaction (non verrouillée), au plus une à la fois. */
    public function brouillon(): HasOne
    {
        return $this->hasOne(VersionKit::class)->where('verrouillee', false);
    }
}
