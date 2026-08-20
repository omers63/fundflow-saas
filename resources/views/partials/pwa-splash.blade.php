@if (\App\Support\AppBrand::hasWordmark())
    @php
        $splashBg = \App\Support\AppBrand::splashBackgroundColor();
        $iconUrl = \App\Support\AppBrand::iconUrl('pwa_512');
        $wordmarkUrl = \App\Support\AppBrand::wordmarkUrl();
    @endphp
    <div id="ff-app-splash" hidden aria-hidden="true">
        <img class="ff-app-splash__icon" src="{{ $iconUrl }}" alt="" width="512" height="512">
        <img class="ff-app-splash__wordmark" src="{{ $wordmarkUrl }}" alt="" width="720" height="240">
    </div>
    <style>
        #ff-app-splash {
            position: fixed;
            inset: 0;
            z-index: 2147483646;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: clamp(0.75rem, 3vh, 1.75rem);
            padding: 12vh 9vw 18vh;
            background:
                {{ $splashBg }}
            ;
            transition: opacity 0.35s ease;
        }

        #ff-app-splash.is-visible {
            display: flex;
        }

        #ff-app-splash.is-hidden {
            opacity: 0;
            pointer-events: none;
        }

        #ff-app-splash .ff-app-splash__icon {
            width: min(42vw, 13.75rem);
            height: auto;
        }

        #ff-app-splash .ff-app-splash__wordmark {
            width: min(82vw, 26rem);
            height: auto;
        }
    </style>
    <script>
        (function () {
            var el = document.getElementById('ff-app-splash');
            if (!el) {
                return;
            }

            var standalone = (window.matchMedia && (
                window.matchMedia('(display-mode: standalone)').matches
                || window.matchMedia('(display-mode: fullscreen)').matches
            ))
                || window.navigator.standalone === true;
            var mobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var shown = false;

            try {
                shown = window.sessionStorage.getItem('ff-splash-shown') === '1';
            } catch (e) { }

            if (reduce || shown || (!standalone && !mobile)) {
                el.remove();
                return;
            }

            try {
                window.sessionStorage.setItem('ff-splash-shown', '1');
            } catch (e) { }

            el.removeAttribute('hidden');
            el.classList.add('is-visible');

            var hide = function () {
                el.classList.add('is-hidden');
                window.setTimeout(function () {
                    if (el && el.parentNode) {
                        el.remove();
                    }
                }, 400);
            };

            var delay = standalone ? 700 : 1100;
            if (document.readyState === 'complete') {
                window.setTimeout(hide, delay);
            } else {
                window.addEventListener('load', function () {
                    window.setTimeout(hide, delay);
                });
            }
        })();
    </script>
@endif