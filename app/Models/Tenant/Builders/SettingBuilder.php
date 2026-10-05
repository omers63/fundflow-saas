<?php

declare(strict_types=1);

namespace App\Models\Tenant\Builders;

use App\Models\Tenant\Setting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bulk writes bypass model events, so they must drop the Setting::get() snapshot themselves.
 *
 * @extends Builder<Setting>
 */
class SettingBuilder extends Builder
{
    public function update(array $values)
    {
        $result = parent::update($values);
        Setting::flushMemo();

        return $result;
    }

    public function delete()
    {
        $result = parent::delete();
        Setting::flushMemo();

        return $result;
    }

    public function insert(array $values)
    {
        $result = $this->toBase()->insert($values);
        Setting::flushMemo();

        return $result;
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $result = parent::upsert($values, $uniqueBy, $update);
        Setting::flushMemo();

        return $result;
    }
}
