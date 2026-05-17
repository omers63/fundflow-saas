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
    }

    .fi-body:has(.fund-auth-shell) .fi-simple-header .fi-logo {
        visibility: hidden;
    }

    .fi-body:has(.fund-auth-shell) .language-switch-dropdown:not(.fi-user-menu) {
        display: none !important;
    }
</style>