@vite(['resources/css/app.css'])

<style>
    [x-cloak] {
        display: none !important;
    }

    .fi-body.fi-panel-admin {
        background: #f9fafb !important;
    }

    .fi-body .fi-simple-layout {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        background: transparent;
    }

    .fi-body .fi-simple-main-ctn {
        flex: 1 1 auto;
        padding-top: 4rem;
        padding-bottom: 2rem;
        background: transparent;
    }

    .fund-auth-shell {
        position: relative;
        z-index: 60;
    }

    .fund-auth-header--filament {
        position: fixed;
        top: 0;
        inset-inline: 0;
        z-index: 60;
        max-width: 100vw;
        overflow: hidden;
    }

    .fund-auth-header--filament .tenant-auth-header__brand {
        flex: 1 1 auto;
        min-width: 0;
        max-width: calc(100% - 9rem);
    }

    .fi-body:has(.fund-auth-shell) .fi-simple-header .fi-logo {
        visibility: hidden;
    }

    .fi-body:has(.fund-auth-shell) .language-switch-dropdown,
    .fi-body:has(.fund-auth-shell) livewire\\:language-switch-component {
        display: none !important;
    }
</style>