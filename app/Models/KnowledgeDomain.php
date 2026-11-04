<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeDomain extends Model
{
    protected $table = 'knowledge_base_domains';

    public $timestamps = false;

    protected $fillable = ['domain'];
}
