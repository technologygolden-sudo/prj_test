<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('categorie')]
#[Fillable(['libelle'])]
class Categorie extends Model
{
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function typesKit(): HasMany
    {
        return $this->hasMany(TypeKit::class);
    }
}
