<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant\MembershipApplication;
use App\Services\MembershipSubscriptionFeeService;
use Filament\Widgets\Widget;

class MembershipApplicationReviewWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.membership-application-review';

    protected int|string|array $columnSpan = 'full';

    public ?MembershipApplication $record = null;

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $application = $this->record;

        if (! $application instanceof MembershipApplication) {
            $owner = $this->getLivewire();

            if (property_exists($owner, 'record') && $owner->record instanceof MembershipApplication) {
                $application = $owner->record;
            }
        }

        if (! $application instanceof MembershipApplication) {
            return [];
        }

        $feeService = app(MembershipSubscriptionFeeService::class);
        $requiresFee = $feeService->applicationRequiresSubscriptionFee($application);
        $hasReceipt = $requiresFee
            ? filled($application->membership_fee_receipt_path)
            : filled($application->application_form_path);
        $hasForm = filled($application->application_form_path);

        $steps = [
            [
                'key' => 'submitted',
                'label' => __('Submitted'),
                'state' => 'complete',
            ],
            [
                'key' => 'documents',
                'label' => __('Documents'),
                'state' => $hasForm && $hasReceipt ? 'complete' : ($application->status === 'pending' ? 'current' : 'upcoming'),
                'description' => $hasForm && $hasReceipt
                    ? __('Form and fee evidence on file.')
                    : __('Confirm signed form and transfer receipt before approval.'),
            ],
            [
                'key' => 'decision',
                'label' => __('Decision'),
                'state' => match ($application->status) {
                    'approved' => 'complete',
                    'rejected' => 'warning',
                    default => 'upcoming',
                },
                'description' => match ($application->status) {
                    'approved' => __('Approved — member account created.'),
                    'rejected' => __('Application was rejected.'),
                    default => __('Use Approve or Reject in the header when ready.'),
                },
            ],
        ];

        return [
            'status' => $application->status,
            'status_label' => MembershipApplication::statusOptions()[$application->status] ?? $application->status,
            'steps' => $steps,
            'requires_fee' => $requiresFee,
            'has_receipt' => $hasReceipt,
            'has_form' => $hasForm,
        ];
    }
}
