<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequest extends Model
{
    public const CATEGORY_GENERAL_INQUIRY = 'general_inquiry';

    public const CATEGORY_CASH_DEPOSIT = 'cash_deposit';

    public const CATEGORY_LOAN_INQUIRY = 'loan_inquiry';

    public const CATEGORY_CONTRIBUTION_QUERY = 'contribution_query';

    public const CATEGORY_BALANCE_QUERY = 'balance_query';

    public const CATEGORY_COMPLAINT = 'complaint';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'member_id',
        'category',
        'subject',
        'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_GENERAL_INQUIRY => __('General inquiry'),
            self::CATEGORY_CASH_DEPOSIT => __('Cash deposit request'),
            self::CATEGORY_LOAN_INQUIRY => __('Loan inquiry'),
            self::CATEGORY_CONTRIBUTION_QUERY => __('Contribution query'),
            self::CATEGORY_BALANCE_QUERY => __('Balance / account query'),
            self::CATEGORY_COMPLAINT => __('Complaint'),
            self::CATEGORY_OTHER => __('Other'),
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return self::categoryOptions()[$category] ?? $category;
    }
}
