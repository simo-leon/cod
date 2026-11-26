{{--
    Notification bar topic (Jemla: Shopify announcement bar as a
    homepage topic — its own per-locale message and optional link).
    Renders nothing when the topic has no message.
--}}
@if (trim((string) ($message ?? '')) !== '')
    <section class="container mt-8 px-[60px] max-1180:px-0 max-lg:px-5 max-sm:px-4">
        <p class="rounded-2xl bg-black px-6 py-3.5 text-center text-sm font-semibold text-white">
            {{ $message }}

            @if (trim((string) ($link ?? '')) !== '')
                <a
                    href="{{ $link }}"
                    class="underline"
                    style="color: #FACC15;"
                >
                    {{ trim((string) ($linkTitle ?? '')) !== '' ? $linkTitle : $link }}
                </a>
            @elseif (trim((string) ($linkTitle ?? '')) !== '')
                <span class="underline">{{ $linkTitle }}</span>
            @endif
        </p>
    </section>
@endif
