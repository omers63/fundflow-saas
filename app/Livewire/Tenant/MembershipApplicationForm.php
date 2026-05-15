<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\MembershipApplication;
use Illuminate\View\View;
use Livewire\Component;

class MembershipApplicationForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $message = '';

    public bool $submitted = false;

    /**
     * @return array<string, string[]>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function submit(): void
    {
        $this->validate();

        MembershipApplication::create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
            'status' => 'pending',
        ]);

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.tenant.membership-application-form');
    }
}
