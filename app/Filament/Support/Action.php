<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Concerns\FormatsFilamentLabel;
use Filament\Actions\Action as BaseAction;

class Action extends BaseAction
{
    use FormatsFilamentLabel;
}
