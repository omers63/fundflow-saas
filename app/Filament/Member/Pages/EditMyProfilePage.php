<?php

namespace App\Filament\Member\Pages;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\HouseholdAccessService;
use App\Support\AppLocale;
use App\Support\StorageFilename;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditMyProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $slug = 'edit-profile';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.member.pages.edit-my-profile';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Edit profile');
    }

    public function getTitle(): string
    {
        return __('Edit profile');
    }

    public function getSubheading(): ?string
    {
        return __('Update your name, contact details, language, avatar, password, and parent PIN.');
    }

    public static function canAccess(): bool
    {
        $user = auth('tenant')->user();

        return $user instanceof User && $user->activeMember() !== null;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $user = auth('tenant')->user();
        $member = $this->currentMember();

        if (!$user instanceof User) {
            return;
        }

        $this->form->fill([
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'preferred_locale' => in_array((string) $user->preferred_locale, ['en', 'ar'], true)
                ? $user->preferred_locale
                : config('app.locale', AppLocale::DEFAULT),
            'avatar' => filled($user->avatar_path)
                ? (User::normalizePublicDiskRelativePath($user->avatar_path) ?? $user->avatar_path)
                : null,
            'remove_avatar' => false,
            'set_parent_pin' => $member?->isParent() ?? false,
            'current_password' => null,
            'new_password' => null,
            'new_password_confirmation' => null,
            'pin' => null,
            'pin_confirmation' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Profile details'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50)
                            ->helperText(__('Used for fund contact and notifications.')),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText(__('Dependents that use a unique login email become separated (direct login enabled). Using household email rejoins and disables direct login.')),
                        Select::make('preferred_locale')
                            ->label(__('Preferred language'))
                            ->options([
                                'en' => __('English'),
                                'ar' => __('Arabic'),
                            ])
                            ->required()
                            ->native(false),
                        FileUpload::make('avatar')
                            ->label(__('Avatar'))
                            ->avatar()
                            ->alignCenter()
                            ->disk('public')
                            ->directory('avatars')
                            ->visibility('public')
                            ->fetchFileInformation(false)
                            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                                return StorageFilename::make('avatar', $file->getClientOriginalName(), [
                                    auth('tenant')->user()?->name,
                                    auth('tenant')->id(),
                                ]);
                            })
                            ->maxSize(2048)
                            ->columnSpanFull(),
                        Toggle::make('remove_avatar')
                            ->label(__('Remove avatar'))
                            ->helperText(__('Enable to remove your existing avatar image.')),
                        TextInput::make('current_password')
                            ->label(__('Current password'))
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->helperText(__('Required only when changing password.')),
                        TextInput::make('new_password')
                            ->label(__('New password'))
                            ->password()
                            ->revealable()
                            ->rules([Password::min(8)->mixedCase()->numbers()])
                            ->dehydrated(false)
                            ->helperText(__('Leave blank to keep current password.')),
                        TextInput::make('new_password_confirmation')
                            ->label(__('Confirm new password'))
                            ->password()
                            ->revealable()
                            ->same('new_password')
                            ->dehydrated(false),
                        Toggle::make('set_parent_pin')
                            ->label(__('Set parent PIN'))
                            ->visible(fn(): bool => $this->currentMember()?->isParent() ?? false),
                        TextInput::make('pin')
                            ->label(__('4-digit PIN'))
                            ->password()
                            ->rules(['nullable', 'digits:4'])
                            ->visible(fn($get): bool => (bool) $get('set_parent_pin')),
                        TextInput::make('pin_confirmation')
                            ->label(__('Confirm PIN'))
                            ->password()
                            ->same('pin')
                            ->visible(fn($get): bool => (bool) $get('set_parent_pin')),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        $member = $this->currentMember();
        $user = auth('tenant')->user();

        if ($member === null || !$user instanceof User) {
            return;
        }

        $data = $this->form->getState();
        $formInput = $this->form->getRawState();
        $oldAvatarPath = $user->avatar_path;

        $rawAvatar = $data['avatar'] ?? null;
        if (is_array($rawAvatar)) {
            $rawAvatar = Arr::first(array_filter(Arr::wrap($rawAvatar), fn($v) => filled($v)));
        }
        $newAvatarPath = filled($rawAvatar) ? (string) $rawAvatar : null;
        $newAvatarPath = User::normalizePublicDiskRelativePath($newAvatarPath) ?? $newAvatarPath;

        if ($newAvatarPath !== null && str_contains($newAvatarPath, 'livewire-tmp')) {
            $newAvatarPath = User::normalizePublicDiskRelativePath((string) $oldAvatarPath) ?? $oldAvatarPath;
        }

        $preferredLocale = $data['preferred_locale'] ?? $user->preferred_locale;
        if (!in_array((string) $preferredLocale, ['en', 'ar'], true)) {
            $preferredLocale = $user->preferred_locale;
        }

        $user->update([
            'name' => (string) $data['name'],
            'phone' => (string) ($data['phone'] ?? ''),
            'preferred_locale' => (string) $preferredLocale,
        ]);

        session()->put('locale', $user->fresh()->preferredLocale());

        $newEmail = (string) $data['email'];
        if ($newEmail !== (string) $user->email) {
            try {
                app(HouseholdAccessService::class)->updateMemberLoginEmail($member, $user, $newEmail);
            } catch (\InvalidArgumentException) {
                Notification::make()
                    ->title(__('Email already in use.'))
                    ->body(__('Choose a unique email, or use your household email to rejoin.'))
                    ->danger()
                    ->send();

                return;
            }
        }

        if ((bool) ($data['remove_avatar'] ?? false)) {
            if (filled($oldAvatarPath)) {
                $del = User::normalizePublicDiskRelativePath((string) $oldAvatarPath) ?? $oldAvatarPath;
                Storage::disk('public')->delete($del);
            }
            $user->forceFill(['avatar_path' => null])->save();
        } elseif (filled($newAvatarPath)) {
            $user->forceFill(['avatar_path' => $newAvatarPath])->save();
            $oldNorm = User::normalizePublicDiskRelativePath((string) ($oldAvatarPath ?? ''));
            if (filled($oldAvatarPath) && $oldNorm !== $newAvatarPath) {
                Storage::disk('public')->delete($oldNorm ?? $oldAvatarPath);
            }
        }

        $user->refresh();
        if (auth('tenant')->id() === $user->id) {
            auth('tenant')->setUser($user);
        }

        $newPassword = (string) ($formInput['new_password'] ?? '');
        if ($newPassword !== '') {
            $currentPassword = (string) ($formInput['current_password'] ?? '');
            $passwordHash = User::query()->whereKey($user->id)->value('password');

            if ($currentPassword === '') {
                Notification::make()
                    ->title(__('Current password is required.'))
                    ->body(__('Enter your current password to set a new one.'))
                    ->danger()
                    ->send();

                return;
            }

            if (!is_string($passwordHash) || !Hash::check($currentPassword, $passwordHash)) {
                Notification::make()
                    ->title(__('Current password is incorrect.'))
                    ->danger()
                    ->send();

                return;
            }

            $user->update(['password' => $newPassword]);
        }

        $shouldSetPin = (bool) ($data['set_parent_pin'] ?? false);
        $pin = (string) ($data['pin'] ?? '');
        if ($member->isParent() && $shouldSetPin && $pin !== '') {
            $member->update(['portal_pin' => Hash::make($pin)]);
        }

        Notification::make()->title(__('Profile updated successfully.'))->success()->send();

        $this->redirect(MemberSettingsPage::getUrl(['tab' => 'profile'], panel: 'member'));
    }

    protected function currentMember(): ?Member
    {
        $user = auth('tenant')->user();

        return $user instanceof User
            ? Member::query()->where('user_id', $user->id)->first()
            : null;
    }
}
