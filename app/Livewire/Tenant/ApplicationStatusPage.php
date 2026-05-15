<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\MembershipApplication;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-public')]
class ApplicationStatusPage extends Component
{
    public string $email = '';

    public string $national_id = '';

    public bool $searched = false;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'national_id' => ['required', 'string', 'min:5', 'max:20'],
        ];
    }

    public function check(): void
    {
        $this->validate();
        $this->searched = true;

        $application = MembershipApplication::query()
            ->where('email', $this->email)
            ->where('national_id', $this->national_id)
            ->latest()
            ->first();

        if ($application === null) {
            $this->result = null;

            return;
        }

        $this->result = [
            'name' => $application->name,
            'status' => $application->status,
            'application_type' => $application->application_type,
            'submitted_at' => $application->created_at?->translatedFormat('d M Y'),
            'reviewed_at' => $application->reviewed_at?->translatedFormat('d M Y'),
            'rejection_reason' => $application->rejection_reason,
            'city' => $application->city,
        ];
    }

    public function render(): View
    {
        return view('livewire.tenant.application-status-page')
            ->title(__('Application status'));
    }
}
