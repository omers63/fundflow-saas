<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

final class TenantPortalViewModal
{
    /**
     * Read-only record detail modals — wider, compact prototype layout.
     */
    public static function apply(Action $action): Action
    {
        return $action
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->extraModalWindowAttributes(['class' => 'ff-tenant-record-modal-window'], merge: true);
    }

    /**
     * Form / compose modals — wider than default without changing submit flow.
     */
    public static function applyToForm(Action $action): Action
    {
        return $action
            ->extraModalWindowAttributes(['class' => 'ff-tenant-form-modal-window'], merge: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    public static function content(array $sections): View
    {
        return view('filament.tenant.partials.view-record-modal', [
            'sections' => $sections,
        ]);
    }
}
