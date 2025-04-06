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

    /**
     * Encode the data attribute into a base64 string before saving to the database.
     * This is done because the data is in binary format.
     *
     * @param string $value
     * @return void
     */
    public function setDataAttribute(string $value): void
    {
        $this->attributes['data'] = base64_encode($value);
    }

    /**
     * Decode the data attribute back into binary data.
     *
     * @return string
     */
    public function getDataAttribute(): string
    {
        $data = $this->attributes['data'];
        if (!is_string($data)) {
            throw new RuntimeException('Invalid data attribute');
        }

        return base64_decode($data);
    }

}
