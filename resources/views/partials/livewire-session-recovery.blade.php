<script>
    document.addEventListener('livewire:init', () => {
        if (!window.Livewire?.interceptRequest) {
            return;
        }

        Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                const status = response?.status;
                if (status !== 419 && status !== 401) {
                    return;
                }

                if (typeof preventDefault === 'function') {
                    preventDefault();
                }

                window.location.reload();
            });
        });
    });
</script>
