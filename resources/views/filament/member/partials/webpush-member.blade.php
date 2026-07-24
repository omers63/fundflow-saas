@php
$vapidPublicKey = config('webpush.vapid.public_key');
$user = auth('tenant')->user();
@endphp
@if (filled($vapidPublicKey) && $user?->activeMember() !== null)
    <script>
        (() => {
            const vapidPublicKey = @json($vapidPublicKey);
            const subscribeUrl = @json(route('tenant.member.webpush.subscribe.store'));
            const csrfToken = @json(csrf_token());

            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !vapidPublicKey) {
                return;
            }

            const urlBase64ToUint8Array = (base64String) => {
                const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
                const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                const rawData = window.atob(base64);
                const outputArray = new Uint8Array(rawData.length);

                for (let i = 0; i < rawData.length; i += 1) {
                    outputArray[i] = rawData.charCodeAt(i);
                }

                return outputArray;
            };

            const ensureServiceWorker = async () => {
                const registration = await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
                await registration.update();

                if (registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }

                await navigator.serviceWorker.ready;

                return registration;
            };

            const syncSubscription = async (subscription) => {
                const json = subscription.toJSON();

                const response = await fetch(subscribeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        endpoint: json.endpoint,
                        keys: json.keys,
                        contentEncoding: 'aes128gcm',
                    }),
                });

                if (!response.ok) {
                    throw new Error('Push subscription sync failed');
                }
            };

            const registerPushSubscription = async () => {
                if (Notification.permission === 'denied') {
                    return;
                }

                if (Notification.permission === 'default') {
                    const permission = await Notification.requestPermission();

                    if (permission !== 'granted') {
                        return;
                    }
                }

                await ensureServiceWorker();

                const registration = await navigator.serviceWorker.ready;
                const subVersionKey = 'ff-webpush-sub-version';
                const subVersion = '2';
                let subscription = await registration.pushManager.getSubscription();

                // Drop browser-local subscriptions that FCM may have already expired (410 Gone),
                // otherwise we keep re-saving dead endpoints into the database.
                if (subscription && localStorage.getItem(subVersionKey) !== subVersion) {
                    await subscription.unsubscribe();
                    subscription = null;
                }

                if (!subscription) {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                    });
                }

                await syncSubscription(subscription);
                localStorage.setItem(subVersionKey, subVersion);
            };

            window.addEventListener('load', () => {
                registerPushSubscription().catch(() => { });
            });
        })();
    </script>
@endif