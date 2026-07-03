<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Lang;
use BackedEnum;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

use function Filament\Support\generate_icon_html;

class UiLabelIcons
{
    /**
     * @var array<string, string|BackedEnum>
     */
    private const KEY_ICONS = [
        // List & resource tabs
        'all' => Heroicon::OutlinedSquares2x2,
        'cash' => Heroicon::OutlinedBanknotes,
        'fund' => Heroicon::OutlinedCircleStack,
        'bank' => Heroicon::OutlinedBuildingLibrary,
        'expense' => Heroicon::OutlinedReceiptPercent,
        'fees' => Heroicon::OutlinedCurrencyDollar,
        'invest' => Heroicon::OutlinedChartBar,
        'suspense' => Heroicon::OutlinedQuestionMarkCircle,
        'loans' => Heroicon::OutlinedDocumentCurrencyDollar,
        'guaranteedloans' => Heroicon::OutlinedShieldCheck,
        'guarantor' => Heroicon::OutlinedShieldCheck,
        'loan' => Heroicon::OutlinedDocumentCurrencyDollar,
        'imports' => Heroicon::OutlinedQueueList,
        'import' => Heroicon::OutlinedQueueList,
        'ledger' => Heroicon::OutlinedBookOpen,
        'statements' => Heroicon::OutlinedDocumentText,
        'statement' => Heroicon::OutlinedDocumentText,
        'transactions' => Heroicon::OutlinedArrowsRightLeft,
        'needs-decision' => Heroicon::OutlinedClipboardDocumentCheck,
        'ready-to-disburse' => Heroicon::OutlinedRocketLaunch,
        'needs_decision' => Heroicon::OutlinedClipboardDocumentCheck,
        'ready_to_disburse' => Heroicon::OutlinedRocketLaunch,
        // Relation managers
        'accounts' => Heroicon::OutlinedWallet,
        'contributions' => Heroicon::OutlinedChartBar,
        'repayments' => Heroicon::OutlinedArrowPath,
        'dependents' => Heroicon::OutlinedUserGroup,
        'household' => Heroicon::OutlinedUserGroup,
        'messages' => Heroicon::OutlinedChatBubbleLeftRight,
        'directmessages' => Heroicon::OutlinedChatBubbleLeftRight,
        'installments' => Heroicon::OutlinedCalendarDays,
        // Settings & forms
        'general' => Heroicon::OutlinedCog6Tooth,
        'public-page' => Heroicon::OutlinedGlobeAlt,
        'public_page' => Heroicon::OutlinedGlobeAlt,
        'contributions-settings' => Heroicon::OutlinedAdjustmentsHorizontal,
        'bank-templates' => Heroicon::OutlinedTableCells,
        'communication' => Heroicon::OutlinedMegaphone,
        'notifications' => Heroicon::OutlinedBellAlert,
        'account' => Heroicon::OutlinedUser,
        'details' => Heroicon::OutlinedClipboardDocumentList,
        'form-upload' => Heroicon::OutlinedArrowUpTray,
        'form_upload' => Heroicon::OutlinedArrowUpTray,
        // Table columns (attribute names)
        'name' => Heroicon::OutlinedUser,
        'email' => Heroicon::OutlinedEnvelope,
        'phone' => Heroicon::OutlinedPhone,
        'mobile_phone' => Heroicon::OutlinedDevicePhoneMobile,
        'member_number' => Heroicon::OutlinedIdentification,
        'national_id' => Heroicon::OutlinedIdentification,
        'amount' => Heroicon::OutlinedCurrencyDollar,
        'balance' => Heroicon::OutlinedScale,
        'balance_after' => Heroicon::OutlinedScale,
        'available_cash' => Heroicon::OutlinedBanknotes,
        'status' => Heroicon::OutlinedSignal,
        'type' => Heroicon::OutlinedTag,
        'description' => Heroicon::OutlinedDocumentText,
        'reference' => Heroicon::OutlinedHashtag,
        'notes' => Heroicon::OutlinedPencilSquare,
        'remarks' => Heroicon::OutlinedChatBubbleBottomCenterText,
        'admin_remarks' => Heroicon::OutlinedChatBubbleBottomCenterText,
        'transaction_date' => Heroicon::OutlinedCalendar,
        'transacted_at' => Heroicon::OutlinedClock,
        'posted_at' => Heroicon::OutlinedCheckCircle,
        'created_at' => Heroicon::OutlinedCalendarDays,
        'updated_at' => Heroicon::OutlinedArrowPath,
        'imported_at' => Heroicon::OutlinedArrowDownTray,
        'statement_date' => Heroicon::OutlinedCalendar,
        'joined_at' => Heroicon::OutlinedCalendarDays,
        'applied_at' => Heroicon::OutlinedCalendar,
        'due_date' => Heroicon::OutlinedCalendarDays,
        'paid_at' => Heroicon::OutlinedCheckBadge,
        'period' => Heroicon::OutlinedCalendar,
        'coverage' => Heroicon::OutlinedChartPie,
        'filename' => Heroicon::OutlinedDocument,
        'bank_name' => Heroicon::OutlinedBuildingLibrary,
        'total_rows' => Heroicon::OutlinedListBullet,
        'imported_rows' => Heroicon::OutlinedArrowDownCircle,
        'duplicate_rows' => Heroicon::OutlinedDocumentDuplicate,
        'monthly_contribution_amount' => Heroicon::OutlinedBanknotes,
        'amount_collected' => Heroicon::OutlinedReceiptPercent,
        'late_fee_collected_amount' => Heroicon::OutlinedExclamationTriangle,
        'late_fee_amount' => Heroicon::OutlinedExclamationCircle,
        'installment_number' => Heroicon::OutlinedHashtag,
        'loan_id' => Heroicon::OutlinedLink,
        'gate' => Heroicon::OutlinedShieldCheck,
        'reason' => Heroicon::OutlinedChatBubbleLeftEllipsis,
        'paid_by_guarantor' => Heroicon::OutlinedUserPlus,
        'is_cleared' => Heroicon::OutlinedCheckCircle,
        'duplicate_of_id' => Heroicon::OutlinedDocumentDuplicate,
        // Nested / dotted column bases
        'parent' => Heroicon::OutlinedUsers,
        'member' => Heroicon::OutlinedUser,
        'approver' => Heroicon::OutlinedUserCircle,
        'bankstatement' => Heroicon::OutlinedFolderOpen,
        'duplicateof' => Heroicon::OutlinedDocumentDuplicate,
        'mastercashtransaction' => Heroicon::OutlinedBuildingOffice2,
        'sender' => Heroicon::OutlinedPaperAirplane,
        'recipient' => Heroicon::OutlinedInbox,
    ];

    /**
     * @return list<string>
     */
    public static function labelKeysFromText(string $label): array
    {
        $slug = Str::slug(Str::transliterate($label, strict: true));

        return array_values(array_unique([$slug, Str::replace('-', '_', $slug)]));
    }

    public static function forKey(string $key): string|BackedEnum|null
    {
        $slug = Str::slug(Str::before($key, '::'));

        if (isset(self::KEY_ICONS[$slug])) {
            return self::KEY_ICONS[$slug];
        }

        foreach (self::KEY_ICONS as $needle => $icon) {
            if (str_contains($slug, $needle)) {
                return $icon;
            }
        }

        return null;
    }

    public static function forLabel(string|Htmlable|null $label): string|BackedEnum|null
    {
        if ($label instanceof Htmlable) {
            $label = strip_tags($label->toHtml() ?? '');
        }

        foreach (self::labelKeysFromText((string) $label) as $key) {
            $icon = self::forKey($key);

            if ($icon !== null) {
                return $icon;
            }
        }

        return null;
    }

    public static function forColumnName(string $name): string|BackedEnum|null
    {
        if ($name === '') {
            return null;
        }

        $segments = explode('.', $name);

        foreach (array_reverse($segments) as $segment) {
            $icon = self::forKey($segment);

            if ($icon !== null) {
                return $icon;
            }
        }

        return self::forKey(str_replace('.', '', $name));
    }

    /**
     * Plain text for Filament table summaries and translation placeholders (not HTML).
     */
    public static function tableModelLabel(string $label): string
    {
        return Lang::formatUiLabel(trim($label));
    }

    public static function labeledHtml(string|Htmlable $label, string|BackedEnum|null $icon = null): Htmlable
    {
        if ($label instanceof Htmlable) {
            $text = trim(strip_tags($label->toHtml() ?? ''));

            if ($text === '') {
                return $label;
            }

            $formatted = Lang::formatUiLabel($text);
        } else {
            $formatted = Lang::formatUiLabel(trim($label));
        }

        $icon ??= self::forLabel($formatted);

        if ($icon === null) {
            return new HtmlString(e($formatted));
        }

        $iconHtml = generate_icon_html(
            $icon,
            attributes: new ComponentAttributeBag(['class' => 'fi-ff-label-icon shrink-0']),
            size: IconSize::Small,
        );

        return new HtmlString(
            '<span class="fi-ff-label-with-icon inline-flex items-center gap-1.5">'
            .($iconHtml?->toHtml() ?? '')
            .'<span class="fi-ff-label-text">'.e($formatted).'</span>'
            .'</span>'
        );
    }
}
