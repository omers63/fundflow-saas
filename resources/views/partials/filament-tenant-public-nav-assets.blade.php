@vite(['resources/css/app.css'])

<style>
    [x-cloak] {
        display: none !important;
    }

    .fi-body.fi-panel-member,
    .fi-body.fi-panel-tenant {
        background: #f9fafb !important;
    }

    .fi-body .fi-simple-layout {
        display: flex;
        flex-direction: column;
        min-height: 100dvh;
        background: transparent;
    }

    .fi-body .fi-simple-main-ctn {
        flex: 1 1 auto;
        padding-top: var(--tenant-public-topbar-offset, 6rem);
        padding-bottom: 1.25rem;
        background: transparent;
    }

    .fi-body:has(.tenant-public-nav) .fi-simple-header .fi-logo {
        visibility: hidden;
    }

    .fi-body:has(.tenant-public-nav) .language-switch-dropdown:not(.fi-user-menu) {
        display: none !important;
    }

    .tenant-public-footer {
        flex-shrink: 0;
    }
</style>