<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\MemberPanelProvider;
use App\Providers\Filament\TenantPanelProvider;
use App\Providers\LocalizationServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    LocalizationServiceProvider::class,
    AdminPanelProvider::class,
    TenantPanelProvider::class,
    MemberPanelProvider::class,
    TenancyServiceProvider::class,
];
