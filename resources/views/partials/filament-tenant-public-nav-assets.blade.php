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
        min-height: 100vh;
        background: transparent;
    }

    .fi-body .fi-simple-main-ctn {
        flex: 1 1 auto;
        padding-top: 4rem;
        padding-bottom: 2rem;
        background: transparent;
    }

    .tenant-public-nav {
        z-index: 60;
    }

    .tenant-public-footer {
        flex-shrink: 0;
    }
</style>