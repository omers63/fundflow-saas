<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\User;
use App\Services\Security\AdminSessionService;
use App\Services\Security\PasskeyService;
use App\Services\Security\TwoFactorService;
use App\Support\SadadSettings;
use App\Support\Security\SecuritySettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use UnitEnum;

class AdminSecurityPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Security';

    protected static ?int $navigationSort = TenantNavigation::SORT_SETTINGS - 2;

    protected static ?string $slug = 'admin-security';

    protected string $view = 'filament.tenant.pages.admin-security';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public ?string $enrollmentSecret = null;

    public ?string $enrollmentUri = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Security');
    }

    public function getSubheading(): ?string
    {
        return __('Two-factor authentication, enforced admin policy, and active sessions.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth('tenant')->user();

        return [
            'user' => $user,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'enforceAdminTwoFactor' => SecuritySettings::enforceAdminTwoFactor(),
            'sessions' => app(AdminSessionService::class)->listForUser((int) $user->id),
            'enrollmentSecret' => $this->enrollmentSecret,
            'enrollmentUri' => $this->enrollmentUri,
            'recoveryCodes' => $this->recoveryCodes,
        ];
    }

    protected function getHeaderActions(): array
    {
        /** @var User $user */
        $user = auth('tenant')->user();

        return [
            TableHeaderIconAction::apply(
                Action::make('begin_2fa')
                    ->label(__('Enable two-factor'))
                    ->icon(Heroicon::OutlinedQrCode)
                    ->visible(fn(): bool => !$user->hasTwoFactorEnabled() && $this->enrollmentSecret === null)
                    ->action(function () use ($user): void {
                        $started = app(TwoFactorService::class)->beginEnrollment($user->fresh());
                        $this->enrollmentSecret = $started['secret'];
                        $this->enrollmentUri = $started['uri'];
                        $this->recoveryCodes = $started['recovery_codes'];
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('confirm_2fa')
                    ->label(__('Confirm two-factor'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->visible(fn(): bool => $this->enrollmentSecret !== null && !$user->fresh()->hasTwoFactorEnabled())
                    ->schema([
                        TextInput::make('code')
                            ->label(__('Authenticator code'))
                            ->required()
                            ->length(6),
                    ])
                    ->action(function (array $data) use ($user): void {
                        try {
                            app(TwoFactorService::class)->confirmEnrollment($user->fresh(), (string) $data['code']);
                            $this->enrollmentSecret = null;
                            $this->enrollmentUri = null;
                            Notification::make()->title(__('Two-factor enabled'))->success()->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Could not enable'))->body($e->getMessage())->danger()->send();
                        }
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('disable_2fa')
                    ->label(__('Disable two-factor'))
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('danger')
                    ->visible(fn(): bool => $user->hasTwoFactorEnabled())
                    ->schema([
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data) use ($user): void {
                        try {
                            app(TwoFactorService::class)->disable($user->fresh(), (string) $data['password']);
                            $this->recoveryCodes = [];
                            Notification::make()->title(__('Two-factor disabled'))->success()->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Could not disable'))->body($e->getMessage())->danger()->send();
                        }
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('toggle_enforce')
                    ->label(__('Toggle enforce 2FA policy'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->schema([
                        Toggle::make('enforce')
                            ->label(__('Require 2FA for all admins'))
                            ->default(SecuritySettings::enforceAdminTwoFactor()),
                    ])
                    ->action(function (array $data): void {
                        SecuritySettings::setEnforceAdminTwoFactor((bool) ($data['enforce'] ?? false));
                        Notification::make()->title(__('Security policy updated'))->success()->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('register_passkey')
                    ->label(__('Register software passkey'))
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->action(function () use ($user): void {
                        $result = app(PasskeyService::class)->registerSoftwarePasskey($user->fresh());
                        Notification::make()
                            ->title(__('Passkey registered'))
                            ->body(__('Credential :id', ['id' => $result['credential']->credential_id]))
                            ->success()
                            ->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('sadad_settings')
                    ->label(__('SADAD registration'))
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->schema([
                        Toggle::make('enabled')->label(__('Enabled'))->default(SadadSettings::enabled()),
                        TextInput::make('biller_id')->label(__('Biller ID'))->default(SadadSettings::billerId()),
                        TextInput::make('merchant_code')->label(__('Merchant code'))->default(SadadSettings::merchantCode()),
                        Select::make('registration_status')
                            ->label(__('Registration status'))
                            ->options([
                                'not_started' => __('Not started'),
                                'pending' => __('Pending'),
                                'active' => __('Active'),
                                'suspended' => __('Suspended'),
                            ])
                            ->default(SadadSettings::registrationStatus()),
                    ])
                    ->action(function (array $data): void {
                        SadadSettings::save($data);
                        Notification::make()->title(__('SADAD settings saved'))->success()->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('revoke_other_sessions')
                    ->label(__('Revoke other sessions'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function () use ($user): void {
                        $count = app(AdminSessionService::class)->revokeOthers((int) $user->id);
                        Notification::make()
                            ->title(__('Sessions revoked'))
                            ->body(__(':count other session(s) ended.', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
            ),
        ];
    }

    public function revokeSession(string $sessionId): void
    {
        /** @var User $user */
        $user = auth('tenant')->user();
        $ok = app(AdminSessionService::class)->revoke($sessionId, (int) $user->id);

        Notification::make()
                    ->title($ok ? __('Session revoked') : __('Could not revoke session'))
            ->{$ok ? 'success' : 'danger'}()
                ->send();
    }
}
