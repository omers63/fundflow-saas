<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\MembershipEnrollmentService;
use App\Support\PublicPageSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.tenant-public')]
class MembershipEnrollmentWizard extends Component
{
    use WithFileUploads;

    public const DOCUMENT_STEP = 4;

    public const FEES_STEP = 5;

    public int $step = 1;

    public string $applicationType = 'new';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $gender = '';

    public string $marital_status = '';

    public string $national_id = '';

    public string $date_of_birth = '';

    public string $address = '';

    public string $city = '';

    public string $home_phone = '';

    public string $work_phone = '';

    public string $mobile_phone = '';

    public string $work_place = '';

    public string $residency_place = '';

    public string $bank_account_number = '';

    public string $iban = '';

    public string $membership_date = '';

    public string $occupation = '';

    public string $employer = '';

    public string $monthly_income = '';

    public string $next_of_kin_name = '';

    public string $next_of_kin_phone = '';

    public string $message = '';

    public $application_form = null;

    public string $membership_fee_transfer_date = '';

    public string $membership_fee_transfer_amount = '';

    public string $membership_fee_transfer_reference = '';

    public bool $membership_fee_acknowledged = false;

    public $membership_fee_receipt = null;

    public bool $submitted = false;

    public bool $enrollmentClosed = false;

    public function mount(): void
    {
        $this->enrollmentClosed = !PublicPageSettings::enrollmentIsOpen();
        $this->resetMembershipFeeTransferFields();
    }

    /**
     * @return list<array{key: string, label: string, subtitle: string}>
     */
    public static function stepDefinitions(bool $includeFeesStep = true): array
    {
        $steps = [
            ['key' => 'personal', 'label' => __('Information'), 'subtitle' => __('Personal details')],
            ['key' => 'identity', 'label' => __('Identity'), 'subtitle' => __('Identity')],
            ['key' => 'work', 'label' => __('Work'), 'subtitle' => __('Work')],
            ['key' => 'document', 'label' => __('Document'), 'subtitle' => __('Document')],
        ];

        if ($includeFeesStep) {
            $steps[] = ['key' => 'fees', 'label' => __('Fees'), 'subtitle' => __('Membership fees')];
        }

        return $steps;
    }

    public function requiresFeePayment(): bool
    {
        return $this->currentApplicationFeeAmount() > 0;
    }

    public function currentApplicationFeeAmount(): float
    {
        return PublicPageSettings::feeForType($this->applicationType);
    }

    public function lastStep(): int
    {
        return $this->requiresFeePayment() ? self::FEES_STEP : self::DOCUMENT_STEP;
    }

    public function stepKindAt(int $step): string
    {
        $sequence = $this->requiresFeePayment()
            ? ['personal', 'identity', 'employment', 'document', 'payment']
            : ['personal', 'identity', 'employment', 'document'];

        return $sequence[$step - 1] ?? 'personal';
    }

    /**
     * @return list<array{key: string, label: string, subtitle: string}>
     */
    public function visibleSteps(): array
    {
        return self::stepDefinitions($this->requiresFeePayment());
    }

    public function stepperCurrentStep(): int
    {
        return min($this->step, $this->lastStep());
    }

    public function updatedApplicationType(): void
    {
        if ($this->step > $this->lastStep()) {
            $this->step = $this->lastStep();
        }

        $this->syncMembershipFeeTransferAmount();
    }

    protected function resetMembershipFeeTransferFields(): void
    {
        $this->membership_fee_transfer_date = now()->toDateString();
        $this->syncMembershipFeeTransferAmount();
    }

    protected function syncMembershipFeeTransferAmount(): void
    {
        $fee = $this->currentApplicationFeeAmount();
        $this->membership_fee_transfer_amount = $fee > 0
            ? number_format($fee, 2, '.', '')
            : '';
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'applicationType' => ['required', 'in:new,resume,renew'],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'gender' => ['nullable', 'in:male,female,other'],
            'marital_status' => ['nullable', 'in:single,married,divorced,widowed,other'],
            'national_id' => ['required', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'home_phone' => ['nullable', 'string', 'max:30'],
            'work_phone' => ['nullable', 'string', 'max:30'],
            'mobile_phone' => ['required', 'string', 'max:30'],
            'work_place' => ['nullable', 'string', 'max:255'],
            'residency_place' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'max:50'],
            'iban' => ['required', 'string', 'max:34'],
            'membership_date' => ['nullable', 'date'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'employer' => ['nullable', 'string', 'max:150'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'next_of_kin_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
            'application_form' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];

        if ($this->requiresFeePayment()) {
            $requiredFee = $this->currentApplicationFeeAmount();
            $rules['membership_fee_transfer_date'] = ['required', 'date', 'before_or_equal:today'];
            $rules['membership_fee_transfer_amount'] = [
                'required',
                'numeric',
                'min:' . $requiredFee,
            ];
            $rules['membership_fee_transfer_reference'] = ['required', 'string', 'min:3', 'max:120'];
            $rules['membership_fee_receipt'] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
            $rules['membership_fee_acknowledged'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    protected function rulesForStep(int $step): array
    {
        return match ($this->stepKindAt($step)) {
            'personal' => [
                'applicationType' => $this->rules()['applicationType'],
                'name' => $this->rules()['name'],
                'email' => $this->rules()['email'],
                'password' => $this->rules()['password'],
            ],
            'identity' => [
                'gender' => $this->rules()['gender'],
                'marital_status' => $this->rules()['marital_status'],
                'national_id' => $this->rules()['national_id'],
                'date_of_birth' => $this->rules()['date_of_birth'],
                'address' => $this->rules()['address'],
                'city' => $this->rules()['city'],
                'home_phone' => $this->rules()['home_phone'],
                'work_phone' => $this->rules()['work_phone'],
                'mobile_phone' => $this->rules()['mobile_phone'],
                'work_place' => $this->rules()['work_place'],
                'residency_place' => $this->rules()['residency_place'],
                'bank_account_number' => $this->rules()['bank_account_number'],
                'iban' => $this->rules()['iban'],
                'membership_date' => $this->rules()['membership_date'],
            ],
            'employment' => [
                'occupation' => $this->rules()['occupation'],
                'employer' => $this->rules()['employer'],
                'monthly_income' => $this->rules()['monthly_income'],
                'next_of_kin_name' => $this->rules()['next_of_kin_name'],
                'next_of_kin_phone' => $this->rules()['next_of_kin_phone'],
            ],
            'document' => [
                'application_form' => $this->rules()['application_form'],
                'message' => $this->rules()['message'],
            ],
            'payment' => $this->requiresFeePayment()
            ? [
                'membership_fee_transfer_date' => $this->rules()['membership_fee_transfer_date'],
                'membership_fee_transfer_amount' => $this->rules()['membership_fee_transfer_amount'],
                'membership_fee_transfer_reference' => $this->rules()['membership_fee_transfer_reference'],
                'membership_fee_receipt' => $this->rules()['membership_fee_receipt'],
                'membership_fee_acknowledged' => $this->rules()['membership_fee_acknowledged'],
            ]
            : [],
            default => [],
        };
    }

    public function nextStep(): void
    {
        $rules = $this->rulesForStep($this->step);

        if ($rules !== []) {
            $this->validate($rules);
        }

        if ($this->step === 1) {
            $this->syncMembershipFeeTransferAmount();
        }

        if ($this->step < $this->lastStep()) {
            $this->step++;
        }
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function submit(MembershipEnrollmentService $enrollmentService): void
    {
        if ($this->enrollmentClosed || $this->step !== $this->lastStep()) {
            return;
        }

        $validated = $this->validate();

        $enrollmentService->submitApplication([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'application_type' => $validated['applicationType'],
            'gender' => filled($this->gender) ? $this->gender : null,
            'marital_status' => filled($this->marital_status) ? $this->marital_status : null,
            'national_id' => $this->national_id,
            'date_of_birth' => $this->date_of_birth,
            'address' => $this->address,
            'city' => $this->city,
            'phone' => $this->mobile_phone,
            'home_phone' => filled($this->home_phone) ? $this->home_phone : null,
            'work_phone' => filled($this->work_phone) ? $this->work_phone : null,
            'mobile_phone' => $this->mobile_phone,
            'work_place' => filled($this->work_place) ? $this->work_place : null,
            'residency_place' => filled($this->residency_place) ? $this->residency_place : null,
            'occupation' => filled($this->occupation) ? $this->occupation : null,
            'employer' => filled($this->employer) ? $this->employer : null,
            'monthly_income' => filled($this->monthly_income) ? $this->monthly_income : null,
            'bank_account_number' => $this->bank_account_number,
            'iban' => strtoupper($this->iban),
            'membership_date' => filled($this->membership_date) ? $this->membership_date : null,
            'next_of_kin_name' => filled($this->next_of_kin_name) ? $this->next_of_kin_name : null,
            'next_of_kin_phone' => filled($this->next_of_kin_phone) ? $this->next_of_kin_phone : null,
            'message' => $validated['message'] ?? null,
            'application_form' => $this->application_form,
            'membership_fee_amount' => $this->requiresFeePayment()
                ? (float) $this->membership_fee_transfer_amount
                : 0,
            'membership_fee_transfer_date' => $this->requiresFeePayment()
                ? $this->membership_fee_transfer_date
                : null,
            'membership_fee_transfer_reference' => $this->requiresFeePayment()
                ? $this->membership_fee_transfer_reference
                : null,
            'membership_fee_receipt' => $this->requiresFeePayment()
                ? $this->membership_fee_receipt
                : null,
            'membership_fee_required_amount' => $this->requiresFeePayment()
                ? $this->currentApplicationFeeAmount()
                : null,
        ]);

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.tenant.membership-enrollment-wizard', [
            'fundName' => PublicPageSettings::fundName(tenant('name')),
            'currency' => Setting::get('general', 'currency', 'USD') ?? 'USD',
            'fees' => [
                'new' => PublicPageSettings::feeNew(),
                'resume' => PublicPageSettings::feeResume(),
                'renew' => PublicPageSettings::feeRenew(),
            ],
            'applicationTypes' => self::applicationTypeOptions(),
            'steps' => $this->visibleSteps(),
            'stepperCurrentStep' => $this->stepperCurrentStep(),
            'lastStep' => $this->lastStep(),
            'requiresFeePayment' => $this->requiresFeePayment(),
            'currentFee' => $this->currentApplicationFeeAmount(),
            'termsDownloadUrl' => PublicPageSettings::termsAndConditionsDownloadUrl(),
            'applicationDocUrl' => PublicPageSettings::membershipApplicationDocumentUrl(),
            'remainingSlots' => PublicPageSettings::remainingEnrollmentSlots(),
            'noLimit' => PublicPageSettings::membershipNoLimit(),
            'feeTransferBankName' => PublicPageSettings::feeTransferBankName(),
            'feeTransferIban' => PublicPageSettings::feeTransferIban(),
        ]);
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function applicationTypeOptions(): array
    {
        return [
            'new' => [
                'label' => __('New'),
                'description' => __('First-time membership in the fund.'),
            ],
            'resume' => [
                'label' => __('Resume'),
                'description' => __('Returning after a break; reactivation.'),
            ],
            'renew' => [
                'label' => __('Renew'),
                'description' => __('Renewing a current membership period.'),
            ],
        ];
    }
}
