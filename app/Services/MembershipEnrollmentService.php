<?php

namespace App\Services;

use App\Models\Tenant\MembershipApplication;
use App\Support\PublicPageSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipEnrollmentService
{
    public function assertEnrollmentOpen(): void
    {
        if (! PublicPageSettings::enrollmentIsOpen()) {
            throw ValidationException::withMessages([
                'enrollment' => __('Membership enrollment is currently closed. The fund has reached its member limit.'),
            ]);
        }
    }

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     application_type: string,
     *     national_id: string,
     *     date_of_birth: string,
     *     address: string,
     *     city: string,
     *     mobile_phone: string,
     *     bank_account_number: string,
     *     iban: string,
     *     next_of_kin_name?: string|null,
     *     next_of_kin_phone?: string|null,
     *     phone?: string|null,
     *     gender?: string|null,
     *     marital_status?: string|null,
     *     home_phone?: string|null,
     *     work_phone?: string|null,
     *     work_place?: string|null,
     *     residency_place?: string|null,
     *     occupation?: string|null,
     *     employer?: string|null,
     *     monthly_income?: float|string|null,
     *     membership_date?: string|null,
     *     message?: string|null,
     *     application_form?: UploadedFile|null,
     *     membership_fee_amount?: float,
     *     membership_fee_transfer_date?: string|null,
     *     membership_fee_transfer_reference?: string|null,
     *     membership_fee_receipt?: UploadedFile|null,
     *     membership_fee_required_amount?: float|null,
     *     parent_member_id?: int|null,
     *     submitted_by_user_id?: int|null,
     *     household_email?: string|null,
     * }  $data
     */
    public function submitApplication(array $data): MembershipApplication
    {
        $this->assertEnrollmentOpen();

        $feeAmount = (float) ($data['membership_fee_amount'] ?? 0);
        $requiredFee = (float) ($data['membership_fee_required_amount'] ?? 0);

        $applicationFormPath = null;
        if ($data['application_form'] instanceof UploadedFile) {
            $extension = $data['application_form']->getClientOriginalExtension();
            $filename = Str::slug($data['name']).'-'.Str::slug($data['national_id']).'-form-'.Str::uuid().'.'.$extension;
            $applicationFormPath = $data['application_form']->storeAs('applications', $filename, 'public');
        }

        $feeReceiptPath = null;
        if ($data['membership_fee_receipt'] instanceof UploadedFile) {
            $extension = $data['membership_fee_receipt']->getClientOriginalExtension();
            $filename = Str::slug($data['name']).'-'.Str::slug($data['national_id']).'-receipt-'.Str::uuid().'.'.$extension;
            $feeReceiptPath = $data['membership_fee_receipt']->storeAs('applications/receipts', $filename, 'public');
        }

        return MembershipApplication::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'household_email' => $data['household_email'] ?? null,
            'parent_member_id' => $data['parent_member_id'] ?? null,
            'submitted_by_user_id' => $data['submitted_by_user_id'] ?? null,
            'password' => $data['password'],
            'phone' => $data['phone'] ?? $data['mobile_phone'],
            'application_type' => $data['application_type'],
            'gender' => $data['gender'] ?? null,
            'marital_status' => $data['marital_status'] ?? null,
            'national_id' => $data['national_id'],
            'date_of_birth' => $data['date_of_birth'],
            'address' => $data['address'],
            'city' => $data['city'],
            'home_phone' => $data['home_phone'] ?? null,
            'work_phone' => $data['work_phone'] ?? null,
            'mobile_phone' => $data['mobile_phone'],
            'occupation' => $data['occupation'] ?? null,
            'employer' => $data['employer'] ?? null,
            'work_place' => $data['work_place'] ?? null,
            'residency_place' => $data['residency_place'] ?? null,
            'monthly_income' => $data['monthly_income'] ?? null,
            'bank_account_number' => $data['bank_account_number'],
            'iban' => $data['iban'],
            'membership_date' => $data['membership_date'] ?? null,
            'next_of_kin_name' => $data['next_of_kin_name'] ?? null,
            'next_of_kin_phone' => $data['next_of_kin_phone'] ?? null,
            'message' => $data['message'] ?? null,
            'application_form_path' => $applicationFormPath,
            'membership_fee_amount' => $feeAmount > 0 ? $feeAmount : null,
            'membership_fee_transfer_date' => $feeAmount > 0
                ? ($data['membership_fee_transfer_date'] ?? null)
                : null,
            'membership_fee_transfer_reference' => $feeAmount > 0
                ? ($data['membership_fee_transfer_reference'] ?? null)
                : null,
            'membership_fee_required_amount' => $feeAmount > 0 && $requiredFee > 0
                ? $requiredFee
                : null,
            'membership_fee_receipt_path' => $feeAmount > 0 ? $feeReceiptPath : null,
            'status' => 'pending',
        ]);
    }
}
