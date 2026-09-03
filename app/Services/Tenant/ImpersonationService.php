<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\ImpersonationAudit;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\AuthSessionPasswordHash;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class ImpersonationService
{
    public const SOURCE_MEMBER_DEPENDENTS = 'member_dependents';

    public const SOURCE_ADMIN = 'admin';

    public const RETURN_PANEL_MEMBER = 'member';

    public const RETURN_PANEL_TENANT = 'tenant';

    private const STACK_KEY = 'impersonation_stack';

    public function start(
        User $impersonator,
        User $impersonatedUser,
        ?Member $impersonatedMember = null,
        string $source = self::SOURCE_MEMBER_DEPENDENTS,
        ?string $returnUrl = null,
    ): void {
        if ($this->isActive()) {
            $this->pushCurrentFrame();
        }

        $returnPanel = $source === self::SOURCE_ADMIN
            ? self::RETURN_PANEL_TENANT
            : self::RETURN_PANEL_MEMBER;
        $safeReturnUrl = $this->sanitizeReturnUrl($returnUrl);

        session([
                        'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $impersonatedUser->id,
            'impersonated_member_id' => $impersonatedMember?->id,
            'impersonation_started_at' => now()->toDateTimeString(),
                        'impersonation_source' => $source,
                        'impersonation_return_panel' => $returnPanel,
                        'impersonation_return_url' => $safeReturnUrl,
            'active_member_id' => $impersonatedMember?->id ?? $impersonatedUser->member?->id,
        ]);

        ImpersonationAudit::create([
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $impersonatedUser->id,
            'impersonated_member_id' => $impersonatedMember?->id,
            'event' => 'started',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => [
                'started_from' => $source,
                'return_panel' => $returnPanel,
                'return_url' => $safeReturnUrl,
                'stack_depth' => $this->stackDepth(),
            ],
            'occurred_at' => now(),
        ]);

        $this->switchToUser($impersonatedUser);
    }

    public function stop(): bool
    {
        $impersonatorId = (int) session('impersonator_user_id');
        $impersonatedUserId = (int) session('impersonated_user_id');
        $impersonatedMemberId = session('impersonated_member_id');
        $source = (string) session('impersonation_source', self::SOURCE_MEMBER_DEPENDENTS);

        if ($impersonatorId <= 0) {
            return false;
        }

        $impersonator = User::find($impersonatorId);
        if ($impersonator === null) {
            return false;
        }

        ImpersonationAudit::create([
            'impersonator_user_id' => $impersonatorId,
            'impersonated_user_id' => $impersonatedUserId ?: $impersonatorId,
            'impersonated_member_id' => is_numeric($impersonatedMemberId) ? (int) $impersonatedMemberId : null,
            'event' => 'stopped',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => [
                'stopped_from' => $source === self::SOURCE_ADMIN ? 'admin_return' : 'member_profile',
                'return_panel' => $this->returnPanel(),
                'return_url' => session('impersonation_return_url'),
                'stack_depth' => $this->stackDepth(),
            ],
            'occurred_at' => now(),
        ]);

        $previous = $this->popFrame();

        if ($previous !== null) {
            $this->restoreFrame($previous);

            $resumeUser = User::query()->find((int) ($previous['impersonated_user_id'] ?? 0));

            if ($resumeUser === null) {
                $this->clearSessionKeys();

                return false;
            }

            $this->switchToUser($resumeUser);

            return true;
        }

        $this->switchToUser($impersonator);

        $this->clearSessionKeys();

        if ($impersonator->member !== null) {
            session(['active_member_id' => $impersonator->member->id]);
        } else {
            session()->forget('active_member_id');
        }

        return true;
    }

    public function isActive(): bool
    {
        return (int) session('impersonator_user_id') > 0;
    }

    public function isAdminImpersonation(): bool
    {
        return $this->isActive()
            && session('impersonation_source') === self::SOURCE_ADMIN;
    }

    /**
     * Admin impersonation may open any member portal status (including blocked),
     * including when nested under admin → parent → dependent.
     */
    public function isAdminImpersonatingUser(User $user): bool
    {
        if (!$this->isActive() || (int) session('impersonated_user_id') !== (int) $user->id) {
            return false;
        }

        if ($this->isAdminImpersonation()) {
            $impersonator = User::query()->find((int) session('impersonator_user_id'));

            return $impersonator?->is_admin === true;
        }

        return $this->stackHasAdminFrame();
    }

    public function returnPanel(): string
    {
        $panel = (string) session('impersonation_return_panel', self::RETURN_PANEL_MEMBER);

        return in_array($panel, [self::RETURN_PANEL_MEMBER, self::RETURN_PANEL_TENANT], true)
            ? $panel
            : self::RETURN_PANEL_MEMBER;
    }

    public function returnUrl(): string
    {
        $custom = session('impersonation_return_url');

        if (is_string($custom) && filled($custom)) {
            return $custom;
        }

        $panelId = $this->returnPanel();
        $fallback = $panelId === self::RETURN_PANEL_TENANT ? '/admin' : '/member';

        return Filament::getPanel($panelId)?->getUrl() ?? $fallback;
    }

    public function returnActionLabel(): string
    {
        return $this->returnPanel() === self::RETURN_PANEL_TENANT
            ? __('Return to admin portal')
            : __('Return to parent portal');
    }

    public function returnModalDescription(): string
    {
        return $this->returnPanel() === self::RETURN_PANEL_TENANT
            ? __('You will switch back to the admin portal.')
            : __('You will switch back to the parent household portal.');
    }

    public function returnSuccessTitle(): string
    {
        return $this->returnPanel() === self::RETURN_PANEL_TENANT
            ? __('Returned to admin portal.')
            : __('Returned to parent portal.');
    }

    /**
     * Whether an admin may start impersonating this member's portal user.
     * Any member with a login account is eligible, regardless of status.
     */
    public function canAdminImpersonate(Member $member): bool
    {
        return $member->user instanceof User;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stack(): array
    {
        $stack = session(self::STACK_KEY, []);

        return is_array($stack) ? array_values($stack) : [];
    }

    private function stackDepth(): int
    {
        return count($this->stack());
    }

    private function stackHasAdminFrame(): bool
    {
        foreach ($this->stack() as $frame) {
            if (($frame['impersonation_source'] ?? null) === self::SOURCE_ADMIN) {
                return true;
            }

            $impersonator = User::query()->find((int) ($frame['impersonator_user_id'] ?? 0));
            if ($impersonator?->is_admin === true) {
                return true;
            }
        }

        return false;
    }

    private function pushCurrentFrame(): void
    {
        $stack = $this->stack();
        $stack[] = [
            'impersonator_user_id' => (int) session('impersonator_user_id'),
            'impersonated_user_id' => (int) session('impersonated_user_id'),
            'impersonated_member_id' => session('impersonated_member_id'),
            'impersonation_started_at' => session('impersonation_started_at'),
            'impersonation_source' => session('impersonation_source'),
            'impersonation_return_panel' => session('impersonation_return_panel'),
            'impersonation_return_url' => session('impersonation_return_url'),
            'active_member_id' => session('active_member_id'),
        ];

        session([self::STACK_KEY => $stack]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function popFrame(): ?array
    {
        $stack = $this->stack();

        if ($stack === []) {
            return null;
        }

        $frame = array_pop($stack);
        session([self::STACK_KEY => $stack]);

        return is_array($frame) ? $frame : null;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function restoreFrame(array $frame): void
    {
        session([
            'impersonator_user_id' => (int) ($frame['impersonator_user_id'] ?? 0),
            'impersonated_user_id' => (int) ($frame['impersonated_user_id'] ?? 0),
            'impersonated_member_id' => $frame['impersonated_member_id'] ?? null,
            'impersonation_started_at' => $frame['impersonation_started_at'] ?? now()->toDateTimeString(),
            'impersonation_source' => $frame['impersonation_source'] ?? self::SOURCE_MEMBER_DEPENDENTS,
            'impersonation_return_panel' => $frame['impersonation_return_panel'] ?? self::RETURN_PANEL_MEMBER,
            'impersonation_return_url' => $frame['impersonation_return_url'] ?? null,
            'active_member_id' => $frame['active_member_id'] ?? null,
        ]);
    }

    private function clearSessionKeys(): void
    {
        session()->forget([
            'impersonator_user_id',
            'impersonated_user_id',
            'impersonated_member_id',
            'impersonation_started_at',
            'impersonation_source',
            'impersonation_return_panel',
            'impersonation_return_url',
            self::STACK_KEY,
        ]);
    }

    private function sanitizeReturnUrl(?string $returnUrl): ?string
    {
        if (!filled($returnUrl)) {
            return null;
        }

        $returnUrl = trim($returnUrl);

        if (str_starts_with($returnUrl, '/')) {
            return $returnUrl;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $returnHost = parse_url($returnUrl, PHP_URL_HOST);
        $returnPath = parse_url($returnUrl, PHP_URL_PATH) ?: '/';
        $returnQuery = parse_url($returnUrl, PHP_URL_QUERY);

        if ($returnHost !== null && $appHost !== null && strcasecmp((string) $returnHost, (string) $appHost) !== 0) {
            $requestHost = request()->getHost();
            if ($requestHost === '' || strcasecmp((string) $returnHost, $requestHost) !== 0) {
                return null;
            }
        }

        if ($returnHost === null && !str_starts_with($returnUrl, 'http')) {
            return null;
        }

        return $returnQuery ? $returnPath . '?' . $returnQuery : $returnPath;
    }

    private function memberGuard(): string
    {
        return Filament::getPanel('member')?->getAuthGuard() ?? 'tenant';
    }

    /**
     * Swap the authenticated user without SessionGuard::login()'s session()->migrate(true).
     *
     * Rotating the session id mid-request races Livewire polls (database notifications)
     * and surfaces the browser confirm: "This page has expired. Would you like to refresh?".
     * Impersonation always starts from an already-authenticated session, so fixation
     * risk from skipping migrate here is low.
     */
    private function switchToUser(User $user): void
    {
        $guardName = $this->memberGuard();
        $guard = Auth::guard($guardName);

        Auth::shouldUse($guardName);
        $guard->setUser($user);
        session()->put($guard->getName(), $user->getAuthIdentifier());
        AuthSessionPasswordHash::syncForUser($user, $guardName);
    }
}
