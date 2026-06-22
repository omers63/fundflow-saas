<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' })
                .then((registration) => {
                    registration.update();

                    if (registration.waiting) {
                        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }

                    registration.addEventListener('updatefound', () => {
                        const installing = registration.installing;

                        if (!installing) {
                            return;
                        }

                        installing.addEventListener('statechange', () => {
                            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                                installing.postMessage({ type: 'SKIP_WAITING' });
                            }
                        });
                    });
                })
                .catch(() => { });
        });
    }
</script>
