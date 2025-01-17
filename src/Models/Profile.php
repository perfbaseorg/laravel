<?php

namespace Perfbase\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class Profile extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'data'
    ];

    /**
     * @param string $input
     * @return array<string, mixed>
     */
    public static function decode(string $input): array
    {

        $gz = gzdecode(base64_decode($input));
        if ($gz === false) {
            throw new RuntimeException('Failed to decompress data');
        }

        /** @var array<string, mixed> $value */
        $value = unserialize($gz);

        if (!is_array($value)) {
            throw new RuntimeException('Corrupted data');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return string
     */
    public static function encode(array $input): string
    {
        $gz = gzencode(serialize($input));

        if ($gz === false) {
            throw new RuntimeException('Failed to compress data');
        }

        return base64_encode($gz);
    }

    /**
     * Get the database connection for the model.
     *
     * @return string
     */
    public function getConnectionName()
    {
        $connection = config('perfbase.cache.config.database.connection');
        if (!is_string($connection)) {
            throw new RuntimeException('Invalid connection name');
        }
        return $connection;
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        $table = config('perfbase.cache.config.database.table');
        if (!is_string($table)) {
            throw new RuntimeException('Invalid table name');
        }

        return $table;
    }
}
