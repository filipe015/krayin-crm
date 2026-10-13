<?php

namespace Webkul\WhatsApp\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key for the REST API. The plain token is shown once, when it is created; only its hash is stored.
 */
class ApiKey extends Model
{
    public const ABILITIES = ['read', 'write'];

    protected $table = 'api_keys';

    protected $fillable = ['name', 'key_prefix', 'key_hash', 'abilities', 'last_used_at', 'last_used_ip', 'revoked_at', 'created_by'];

    protected $casts = [
        'abilities'    => 'array',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    /**
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string}  the key and its plain token
     */
    public static function issue(string $name, array $abilities, ?int $userId): array
    {
        $token = 'kcrm_'.Str::random(40);

        $key = static::create([
            'name'       => $name,
            'key_prefix' => substr($token, 0, 10),
            'key_hash'   => hash('sha256', $token),
            'abilities'  => array_values(array_intersect(self::ABILITIES, $abilities)) ?: ['read'],
            'created_by' => $userId,
        ]);

        return [$key, $token];
    }

    public static function findByToken(string $token): ?self
    {
        if (! str_starts_with($token, 'kcrm_') || strlen($token) > 80) {
            return null;
        }

        return static::query()
            ->where('key_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();
    }

    public function can(string $ability): bool
    {
        return in_array($ability, (array) $this->abilities, true);
    }
}
