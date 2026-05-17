<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
#[Title('Sign in')]
class MemberLoginPage extends Component
{
    public string $email = '';

    public string $password = '';

    public string $verificationSecret = '';

    public bool $remember = false;

    public bool $showProfilePicker = false;

    public ?int $householdParentId = null;

    public ?int $selectedMemberId = null;

    /** @var list<array{id: int, name: string, is_parent: bool, is_separated: bool, avatar_url: ?string}> */
    public array $availableProfiles = [];

    public ?string $statusMessage = null;

    public ?string $statusType = null;

    public function mount(): void
    {
        if (Auth::guard('tenant')->check()) {
            $user = Auth::guard('tenant')->user();

            if ($user instanceof User) {
                if ($user->canAccessPanel(Filament::getPanel('member'))) {
                    $this->redirect(Filament::getPanel('member')->getUrl());

                    return;
                }

                if ($user->canAccessPanel(Filament::getPanel('tenant'))) {
                    $this->redirect(Filament::getPanel('tenant')->getUrl());

                    return;
                }
            }

            Auth::guard('tenant')->logout();
        }

        if (session()->pull('member_suspended_notice')) {
            $this->statusType = 'suspended';
            $this->statusMessage = __('Your member portal access is currently suspended. Please contact fund administration for support.');
        } elseif (session()->pull('member_withdrawn_notice')) {
            $this->statusType = 'withdrawn';
            $this->statusMessage = __('Your membership has been withdrawn. Member portal access is no longer available. Please contact fund administration for support.');
        } elseif (session()->pull('member_delinquent_notice')) {
            $this->statusType = 'delinquent';
            $this->statusMessage = __('Your membership is marked delinquent. Member portal access is restricted until fund administration resolves outstanding items.');
        } elseif (session()->pull('member_terminated_notice')) {
            $this->statusType = 'terminated';
            $this->statusMessage = __('Your membership has been terminated. Member portal access is no longer available. Please contact fund administration for support.');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function login(): void
    {
        $this->validate();
        $this->resetErrorBag();

        if (! $this->ensureIsNotRateLimited('login')) {
            return;
        }

        $directUser = $this->resolveDirectUser();
        if ($directUser !== null) {
            RateLimiter::clear($this->throttleKey('login'));
            $this->completeLogin($directUser);

            return;
        }

        $householdParent = $this->resolveHouseholdParent();
        if ($householdParent === null) {
            if (
                Auth::guard('tenant')->attempt(
                    ['email' => $this->email, 'password' => $this->password],
                    $this->remember,
                )
            ) {
                $user = Auth::guard('tenant')->user();
                if ($user instanceof User && $user->canAccessPanel(Filament::getPanel('member'))) {
                    RateLimiter::clear($this->throttleKey('login'));
                    $this->completeLogin($user);

                    return;
                }

                Auth::guard('tenant')->logout();
            }

            $adminUser = $this->resolveAdminUser();
            if ($adminUser !== null) {
                RateLimiter::clear($this->throttleKey('login'));
                $this->completeLogin($adminUser);

                return;
            }

            RateLimiter::hit($this->throttleKey('login'), 300);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if (! Hash::check($this->password, (string) $householdParent->user?->password)) {
            RateLimiter::hit($this->throttleKey('login'), 300);

            throw ValidationException::withMessages([
                'password' => __('The provided credentials are incorrect.'),
            ]);
        }

        if (! $householdParent->dependents()->exists()) {
            RateLimiter::clear($this->throttleKey('login'));
            $this->completeLogin($householdParent->user);

            return;
        }

        RateLimiter::clear($this->throttleKey('login'));

        $this->householdParentId = $householdParent->id;
        $this->showProfilePicker = true;
        $this->loadAvailableProfiles($householdParent);
        $this->password = '';
    }

    public function selectProfile(int|string $memberId): void
    {
        if (! $this->showProfilePicker || $this->householdParentId === null) {
            return;
        }

        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            $this->selectedMemberId = null;

            return;
        }

        $selected = $this->findHouseholdMember($memberId);
        if ($selected === null) {
            $this->addError('selectedMemberId', __('Invalid profile selected.'));

            return;
        }

        $this->selectedMemberId = $selected->id;
        $this->resetErrorBag('selectedMemberId');
    }

    public function verifySelectedProfile(): void
    {
        $this->resetErrorBag('verificationSecret');

        if (! $this->ensureIsNotRateLimited('profile_verification')) {
            return;
        }

        if ($this->householdParentId === null || $this->selectedMemberId === null) {
            $this->addError('selectedMemberId', __('Please select a profile.'));

            return;
        }

        $selected = $this->findHouseholdMember($this->selectedMemberId);
        if ($selected === null) {
            $this->addError('selectedMemberId', __('Invalid profile selected.'));

            return;
        }

        if ($selected->id === $this->householdParentId) {
            $pinHash = (string) ($selected->portal_pin ?? '');
            $isValidParentSecret = $pinHash !== ''
                ? Hash::check($this->verificationSecret, $pinHash)
                : Hash::check($this->verificationSecret, (string) $selected->user?->password);

            if (! $isValidParentSecret) {
                RateLimiter::hit($this->throttleKey('profile_verification'), 300);
                $this->addError('verificationSecret', __('The parent PIN is incorrect.'));

                return;
            }
        } elseif (! Hash::check($this->verificationSecret, (string) $selected->user?->password)) {
            RateLimiter::hit($this->throttleKey('profile_verification'), 300);
            $this->addError('verificationSecret', __('The dependent password is incorrect.'));

            return;
        }

        RateLimiter::clear($this->throttleKey('profile_verification'));
        $this->completeLogin($selected->user);
    }

    public function backToEmailStep(): void
    {
        $this->showProfilePicker = false;
        $this->householdParentId = null;
        $this->selectedMemberId = null;
        $this->availableProfiles = [];
        $this->verificationSecret = '';
    }

    public function render(): View
    {
        return view('livewire.tenant.member-login-page');
    }

    protected function completeLogin(User $user): void
    {
        $memberPanel = Filament::getPanel('member');
        $tenantPanel = Filament::getPanel('tenant');

        if ($user->canAccessPanel($memberPanel)) {
            $member = $user->member;

            if ($member === null) {
                throw ValidationException::withMessages([
                    'email' => __('No member account is linked to this login.'),
                ]);
            }

            if ($member->status === 'suspended') {
                $this->statusType = 'suspended';
                $this->statusMessage = __('Your member portal access is currently suspended. Please contact fund administration for support.');

                return;
            }

            if ($member->status === 'withdrawn') {
                $this->statusType = 'withdrawn';
                $this->statusMessage = __('Your membership has been withdrawn. Member portal access is no longer available. Please contact fund administration for support.');

                return;
            }

            if ($member->status === 'delinquent') {
                $this->statusType = 'delinquent';
                $this->statusMessage = __('Your membership is marked delinquent. Member portal access is restricted until fund administration resolves outstanding items.');

                return;
            }

            if ($member->status === 'terminated') {
                $this->statusType = 'terminated';
                $this->statusMessage = __('Your membership has been terminated. Member portal access is no longer available. Please contact fund administration for support.');

                return;
            }

            Auth::guard('tenant')->login($user, $this->remember);
            session()->regenerate();
            session()->put('locale', $user->preferredLocale());

            $this->redirectIntended($memberPanel->getUrl());

            return;
        }

        if ($user->canAccessPanel($tenantPanel)) {
            Auth::guard('tenant')->login($user, $this->remember);
            session()->regenerate();
            session()->put('locale', $user->preferredLocale());

            $this->redirectIntended($tenantPanel->getUrl());

            return;
        }

        throw ValidationException::withMessages([
            'email' => __('No member account is linked to this login. If you applied for membership, check your application status.'),
        ]);
    }

    protected function resolveAdminUser(): ?User
    {
        $user = User::query()
            ->where('email', $this->email)
            ->where('is_admin', true)
            ->first();

        if ($user === null) {
            return null;
        }

        if (! Hash::check($this->password, (string) $user->password)) {
            return null;
        }

        return $user;
    }

    protected function resolveDirectUser(): ?User
    {
        $user = User::query()
            ->where('email', $this->email)
            ->whereHas('member', fn ($q) => $q
                ->whereNotNull('parent_member_id')
                ->where('direct_login_enabled', true))
            ->first();

        if ($user === null) {
            return null;
        }

        if (! Hash::check($this->password, (string) $user->password)) {
            return null;
        }

        return $user;
    }

    protected function resolveHouseholdParent(): ?Member
    {
        return Member::query()
            ->with('user')
            ->whereNull('parent_member_id')
            ->where(function ($query): void {
                $query->where('household_email', $this->email)
                    ->orWhere(function ($nested): void {
                        $nested->whereNull('household_email')->where('email', $this->email);
                    });
            })
            ->first();
    }

    protected function findHouseholdMember(int $memberId): ?Member
    {
        if ($this->householdParentId === null) {
            return null;
        }

        return Member::query()
            ->with('user')
            ->whereKey($memberId)
            ->where(function ($query): void {
                $query
                    ->whereKey($this->householdParentId)
                    ->orWhere('parent_member_id', $this->householdParentId);
            })
            ->first();
    }

    protected function loadAvailableProfiles(Member $householdParent): void
    {
        $profiles = Member::query()
            ->with('user')
            ->where(function ($query) use ($householdParent): void {
                $query->whereKey($householdParent->id)
                    ->orWhere('parent_member_id', $householdParent->id);
            })
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$householdParent->id])
            ->get();

        $this->availableProfiles = $profiles
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => (string) ($member->user?->name ?? $member->name),
                'is_parent' => $member->id === $householdParent->id,
                'is_separated' => (bool) $member->is_separated,
                'avatar_url' => $member->user?->avatarPublicUrl(),
            ])
            ->all();
    }

    protected function ensureIsNotRateLimited(string $scope): bool
    {
        $key = $this->throttleKey($scope);
        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return true;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    protected function throttleKey(string $scope): string
    {
        return $scope.'|'.Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
