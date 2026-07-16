<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Pages;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MembershipApplications\Schemas\MembershipApplicationForm;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\Tenant\MembershipApplicationNotificationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateMembershipApplication extends CreateRecord
{
    protected static string $resource = MembershipApplicationResource::class;

    public function mount(): void
    {
        parent::mount();

        $parentMemberId = request()->integer('parent_member_id');

        if ($parentMemberId <= 0) {
            return;
        }

        $parent = Member::query()->find($parentMemberId);

        if ($parent === null) {
            return;
        }

        $this->form->fill([
            'parent_member_id' => $parent->id,
            'household_email' => $parent->email,
            'application_type' => 'new',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return MembershipApplicationForm::configure($schema, forCreate: true);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['password_confirmation']);

        $data['status'] = 'pending';

        if (filled($data['iban'] ?? null)) {
            $data['iban'] = strtoupper((string) $data['iban']);
        }

        if (filled($data['mobile_phone'] ?? null)) {
            $data['phone'] = $data['mobile_phone'];
        }

        if (filled($data['parent_member_id'] ?? null)) {
            $data['submitted_by_user_id'] = auth('tenant')->id();
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): MembershipApplication
    {
        $application = MembershipApplication::create($data);

        app(MembershipApplicationNotificationService::class)->notifyAdminsOfSubmission($application);

        return $application;
    }

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord();

        return Notification::make()
            ->title(__('Application created'))
            ->body(__('Pending application for :name has been created.', ['name' => $record->name]))
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
