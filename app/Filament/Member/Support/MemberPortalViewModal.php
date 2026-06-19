<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Illuminate\Contracts\View\View;

final class MemberPortalViewModal
{
    /**
     * Read-only record detail modals — wider, compact prototype layout.
     */
    public static function apply(ViewAction $action): ViewAction
    {
        return $action
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->extraModalWindowAttributes(['class' => 'ff-member-record-modal-window']);
    }

    /**
     * Form / compose modals — wider than default lg without changing submit flow.
     */
    public static function applyToForm(Action $action): Action
    {
        return $action
            ->modalWidth('2xl')
            ->extraModalWindowAttributes(['class' => 'ff-member-form-modal-window']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    public static function content(array $sections): View
    {
        return view('filament.member.partials.view-record-modal', [
            'sections' => $sections,
        ]);
    }
}
