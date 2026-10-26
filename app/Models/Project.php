<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $fillable = ['name', 'public_key', 'secret_key_hash', 'allowed_origins'];

    protected $casts = ['allowed_origins' => 'array'];

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function findBySecret(string $secret): ?self
    {
        return static::where('secret_key_hash', self::hashSecret($secret))->first();
    }

    /**
     * Create a project with fresh keys. The plain secret is returned once and never stored.
     *
     * @return array{0: self, 1: string} [project, plainSecret]
     */
    public static function issue(string $name, ?array $allowedOrigins = null): array
    {
        $secret = 'sk_'.Str::random(40);

        $project = static::create([
            'name' => $name,
            'public_key' => 'pk_'.Str::random(32),
            'secret_key_hash' => self::hashSecret($secret),
            'allowed_origins' => $allowedOrigins ?: null,
        ]);

        return [$project, $secret];
    }
}
