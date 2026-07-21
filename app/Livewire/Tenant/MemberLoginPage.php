<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Tenant\HouseholdProfileVerificationService;
use App\Services\Tenant\MemberHouseholdLoginService;
use App\Services\Tenant\MemberRequestService;
use App\Support\MemberMembershipPolicy;
use App\Support\MemberPortalMaintenance;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
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
    public const STATUS_REQUEST_SESSION_KEY = 'member_login_status_request';

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

    public bool $showStatusRequestForm = false;

    public string $statusRequestType = '';

    public string $statusRequestReason = '';

    public ?string $statusRequestSuccess = null;

    /** @var list<string> */
    public array $availableStatusRequestTypes = [];

    public function mount(): void
    {
        if (Auth::guard('tenant')->check()) {
            $user = Auth::guard('tenant')->user();

            if ($user instanceof User) {
                if (
                    MemberPortalMaintenance::isEnabled()
                    && ! MemberPortalMaintenance::isExempt(request())
                    && ! MemberPortalMaintenance::sessionEpochIsValid()
                ) {
                    Auth::guard('tenant')->logout();
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                } elseif ($user->canAccessPanel(Filament::getPanel('member'))) {
                    $this->redirect(Filament::getPanel('member')->getUrl());

                    return;
                } elseif ($user->canAccessPanel(Filament::getPanel('tenant'))) {
                    $this->redirect(Filament::getPanel('tenant')->getUrl());

                    return;
                } else {
                    Auth::guard('tenant')->logout();
                }
            } else {
                Auth::guard('tenant')->logout();
            }
        }

        if (session()->pull(MemberPortalMaintenance::MAINTENANCE_NOTICE_SESSION_KEY)) {
            $this->applyMaintenanceStatus();
        } elseif (MemberPortalMaintenance::isEnabled() && ! MemberPortalMaintenance::isExempt(request())) {
            $this->applyMaintenanceStatus();
        } elseif (session()->pull('member_suspended_notice')) {
            $this->statusType = 'suspended';
            $this->statusMessage = __('Your member portal access is currently suspended. Please contact fund administration for support.');
        } elseif (session()->pull('member_inactive_notice')) {
            $this->statusType = 'inactive';
            $this->statusMessage = __('Your membership is inactive (frozen). Sign in below to request an unfreeze, or contact fund administration.');
        } elseif (session()->pull('member_withdrawn_notice')) {
            $this->statusType = 'withdrawn';
            $this->statusMessage = __('Your membership has ended. Sign in below to request reinstatement, or contact fund administration.');
        } elseif (session()->pull('member_delinquent_notice')) {
            $this->statusType = 'delinquent';
            $this->statusMessage = __('Your membership is marked delinquent. Member portal access is restricted until fund administration resolves outstanding items.');
        } elseif (session()->pull('member_terminated_notice')) {
            $this->statusType = 'terminated';
            $this->statusMessage = __('Your membership was terminated with payout on hold. Sign in below to request payout release or reinstatement, or contact fund administration.');
        }

        $this->restoreStatusRequestSession();
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
        $this->clearStatusRequestUi();

        $this->email = app(MemberHouseholdLoginService::class)->normalizeEmail($this->email);

        if (! $this->ensureIsNotRateLimited('login')) {
            return;
        }

        if (MemberPortalMaintenance::isEnabled()) {
            $adminUser = $this->resolveAdminUser();
            if ($adminUser !== null) {
                RateLimiter::clear($this->throttleKey('login'));
                $this->completeLogin($adminUser);

                return;
            }

            $this->applyMaintenanceStatus();

            return;
        }

        $loginService = app(MemberHouseholdLoginService::class);

        $directUser = $loginService->resolveDirectLoginUser($this->email, $this->password);
        if ($directUser !== null) {
            RateLimiter::clear($this->throttleKey('login'));
            $this->completeLogin($directUser, $directUser->member);

            return;
        }

        $householdParent = $loginService->resolveHouseholdParent($this->email);
        if ($householdParent === null) {
            $memberUser = $loginService->resolveMemberUserByCredentials($this->email, $this->password);

            if ($memberUser !== null) {
                RateLimiter::clear($this->throttleKey('login'));
                $this->completeLogin($memberUser, $memberUser->member);

                return;
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

        if (! $loginService->verifyPassword($householdParent->user, $this->password)) {
            RateLimiter::hit($this->throttleKey('login'), 300);

            throw ValidationException::withMessages([
                'password' => __('The provided credentials are incorrect.'),
            ]);
        }

        if (! $householdParent->dependents()->exists()) {
            RateLimiter::clear($this->throttleKey('login'));
            $this->completeLogin($householdParent->user, $householdParent);

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

        if (MemberPortalMaintenance::isEnabled() && ! MemberPortalMaintenance::isExempt(request())) {
            $this->applyMaintenanceStatus();

            return;
        }

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

        if (! app(HouseholdProfileVerificationService::class)->memberCanUsePortal($selected)) {
            if (! in_array($selected->status, Member::PORTAL_BLOCKED_STATUSES, true)) {
                $this->addError('selectedMemberId', __('This profile is not available for portal access.'));

                return;
            }
        }

        if ($selected->user === null) {
            $this->addError('selectedMemberId', __('This profile does not have a login account yet.'));

            return;
        }

        $householdParent = Member::query()->find($this->householdParentId);
        $verifier = app(HouseholdProfileVerificationService::class);

        if (! $verifier->verifyMemberSecret($selected, $this->verificationSecret, $householdParent)) {
            RateLimiter::hit($this->throttleKey('profile_verification'), 300);

            $this->addError(
                'verificationSecret',
                $selected->id === $this->householdParentId
                ? __('The parent PIN is incorrect.')
                : __('The dependent password is incorrect.'),
            );

            return;
        }

        RateLimiter::clear($this->throttleKey('profile_verification'));
        $this->completeLogin($selected->user->fresh(), $selected);
    }

    public function backToEmailStep(): void
    {
        $this->showProfilePicker = false;
        $this->householdParentId = null;
        $this->selectedMemberId = null;
        $this->availableProfiles = [];
        $this->verificationSecret = '';
        session()->forget('active_member_id');
    }

    public function clearBlockedStatusRequest(): void
    {
        $this->clearStatusRequestUi();
        session()->forget(self::STATUS_REQUEST_SESSION_KEY);
        $this->statusType = null;
        $this->statusMessage = null;
        $this->password = '';
    }

    public function submitStatusRequest(): void
    {
        $this->resetErrorBag();
        $this->statusRequestSuccess = null;

        $session = session(self::STATUS_REQUEST_SESSION_KEY);

        if (! is_array($session) || ! isset($session['member_id'], $session['user_id'])) {
            $this->addError('statusRequestReason', __('Sign in again to submit a membership request.'));

            return;
        }

        $member = Member::query()->find((int) $session['member_id']);

        if (
            ! $member instanceof Member
            || (int) $member->user_id !== (int) $session['user_id']
        ) {
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);
            $this->clearStatusRequestUi();
            $this->addError('statusRequestReason', __('Sign in again to submit a membership request.'));

            return;
        }

        $allowed = MemberRequest::loginSurfaceTypesFor($member);

        if ($allowed === []) {
            $this->addError('statusRequestReason', __('No membership request is available for your account status.'));

            return;
        }

        $type = $this->statusRequestType !== '' ? $this->statusRequestType : $allowed[0];

        if (! in_array($type, $allowed, true)) {
            $this->addError('statusRequestType', __('Choose a valid request type.'));

            return;
        }

        $this->validate([
            'statusRequestReason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(MemberRequestService::class)->submit($member, $type, [
                'reason' => $this->statusRequestReason,
            ]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
            $this->addError('statusRequestReason', $message);

            return;
        }

        $this->statusRequestSuccess = __('Request submitted. Fund administration will review it shortly.');
        $this->statusRequestReason = '';

        $this->availableStatusRequestTypes = array_values(array_filter(
            MemberRequest::loginSurfaceTypesFor($member->fresh() ?? $member),
            function (string $availableType) use ($member): bool {
                return ! MemberRequest::query()
                    ->where('requester_member_id', $member->id)
                    ->where('type', $availableType)
                    ->where('status', MemberRequest::STATUS_PENDING)
                    ->exists();
            },
        ));

        if ($this->availableStatusRequestTypes === []) {
            $this->showStatusRequestForm = false;
            $this->statusRequestType = '';
        } else {
            $this->statusRequestType = $this->availableStatusRequestTypes[0];
        }
    }

    public function render(): View
    {
        return view('livewire.tenant.member-login-page');
    }

    protected function completeLogin(User $user, ?Member $authenticatedMember = null): void
    {
        $memberPanel = Filament::getPanel('member');
        $tenantPanel = Filament::getPanel('tenant');

        $member = $authenticatedMember ?? $user->member;

        if ($member !== null) {
            if ((int) $member->user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'email' => __('No member account is linked to this login.'),
                ]);
            }

            if (MemberPortalMaintenance::isEnabled() && ! MemberPortalMaintenance::isExempt(request())) {
                if ($user->is_admin && $user->canAccessPanel($tenantPanel)) {
                    Auth::guard('tenant')->login($user, $this->remember);
                    session()->regenerate();
                    session()->put('locale', $user->preferredLocale());

                    $this->redirectIntended($tenantPanel->getUrl());

                    return;
                }

                $this->applyMaintenanceStatus();

                return;
            }

            if (! app(MemberMembershipPolicy::class)->canAccessPortal($member)) {
                $this->applyPortalBlockedStatus($member, $user);

                return;
            }

            Auth::guard('tenant')->login($user, $this->remember);
            session()->regenerate();
            session()->put('locale', $user->preferredLocale());
            session()->put('active_member_id', $member->id);
            MemberPortalMaintenance::syncSessionEpoch();
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);

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

    protected function applyPortalBlockedStatus(Member $member, ?User $user = null): void
    {
        $this->password = '';
        $this->showProfilePicker = false;
        $this->verificationSecret = '';

        if ($member->status === 'inactive') {
            if ($member->frozen_at !== null) {
                $this->statusType = 'inactive';
                $this->statusMessage = __('Your membership is inactive (frozen). Member portal access is paused until your account is unfrozen.');
                $this->enableStatusRequestForm($member, $user);

                return;
            }

            $this->statusType = 'suspended';
            $this->statusMessage = __('Your member portal access is currently suspended. Please contact fund administration for support.');
            $this->clearStatusRequestUi();
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);

            return;
        }

        if ($member->status === 'withdrawn') {
            if ($member->payout_frozen_at !== null) {
                $this->statusType = 'terminated';
                $this->statusMessage = __('Your membership was terminated with payout on hold. You can request payout release or reinstatement below.');
                $this->enableStatusRequestForm($member, $user);

                return;
            }

            $this->statusType = 'withdrawn';
            $this->statusMessage = __('Your membership has ended. You can request reinstatement below, or contact fund administration.');
            $this->enableStatusRequestForm($member, $user);

            return;
        }

        if (app(LoanDelinquencyService::class)->isDelinquent($member)) {
            $this->statusType = 'delinquent';
            $this->statusMessage = __('Your membership is marked delinquent. Member portal access is restricted until fund administration resolves outstanding items.');
            $this->clearStatusRequestUi();
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);
        }
    }

    protected function enableStatusRequestForm(Member $member, ?User $user = null): void
    {
        $types = array_values(array_filter(
            MemberRequest::loginSurfaceTypesFor($member),
            function (string $type) use ($member): bool {
                return ! MemberRequest::query()
                    ->where('requester_member_id', $member->id)
                    ->where('type', $type)
                    ->where('status', MemberRequest::STATUS_PENDING)
                    ->exists();
            },
        ));

        if ($types === [] || $user === null) {
            $this->clearStatusRequestUi();
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);

            return;
        }

        session([
            self::STATUS_REQUEST_SESSION_KEY => [
                'member_id' => $member->id,
                'user_id' => $user->id,
            ],
        ]);

        $this->availableStatusRequestTypes = $types;
        $this->statusRequestType = $types[0];
        $this->statusRequestReason = '';
        $this->statusRequestSuccess = null;
        $this->showStatusRequestForm = true;
    }

    protected function restoreStatusRequestSession(): void
    {
        $session = session(self::STATUS_REQUEST_SESSION_KEY);

        if (! is_array($session) || ! isset($session['member_id'], $session['user_id'])) {
            return;
        }

        $member = Member::query()->find((int) $session['member_id']);

        if (
            ! $member instanceof Member
            || (int) $member->user_id !== (int) $session['user_id']
        ) {
            session()->forget(self::STATUS_REQUEST_SESSION_KEY);

            return;
        }

        if (! app(MemberMembershipPolicy::class)->canAccessPortal($member)) {
            $user = User::query()->find((int) $session['user_id']);
            $this->applyPortalBlockedStatus($member, $user instanceof User ? $user : null);
        }
    }

    protected function clearStatusRequestUi(): void
    {
        $this->showStatusRequestForm = false;
        $this->statusRequestType = '';
        $this->statusRequestReason = '';
        $this->statusRequestSuccess = null;
        $this->availableStatusRequestTypes = [];
    }

    protected function applyMaintenanceStatus(): void
    {
        $this->statusType = 'maintenance';
        $this->statusMessage = MemberPortalMaintenance::message();
        $this->showProfilePicker = false;
        $this->householdParentId = null;
        $this->selectedMemberId = null;
        $this->availableProfiles = [];
        $this->verificationSecret = '';
        $this->password = '';
        $this->clearStatusRequestUi();
        session()->forget(self::STATUS_REQUEST_SESSION_KEY);
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

        if (! app(MemberHouseholdLoginService::class)->verifyPassword($user, $this->password)) {
            return null;
        }

        return $user;
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
