<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.conversations.show-title', ['phone' => $conversation->phone])
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white" dir="ltr">
                {{ $conversation->phone }}
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.conversations.show-subtitle')
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if (! $conversation->isEscalated())
                <form method="POST" action="{{ route('admin.cod.conversations.handoff', $conversation) }}">
                    @csrf

                    <button
                        type="submit"
                        class="whitespace-nowrap rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700"
                        onclick="return confirm(@js(trans('admin::app.cod.conversations.handoff-confirm')))"
                    >
                        @lang('admin::app.cod.conversations.handoff')
                    </button>
                </form>
            @endif

            <a href="{{ $indexUrl }}" class="secondary-button">
                @lang('admin::app.cod.conversations.back-to-conversations')
            </a>
        </div>
    </div>

    @if ($conversation->isEscalated())
        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200">
            @lang('admin::app.cod.conversations.escalated-notice')
        </div>
    @endif

    <!-- Facts: state, order, product, collected information -->
    <div class="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.state')"
            :value="trans('admin::app.cod.conversations.states.'.$conversation->state)"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.order')"
            :value="$conversation->codOrder ? '#'.$conversation->codOrder->external_id : '—'"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.product')"
            :value="$conversation->product()['name'] ?? '—'"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.ai')"
            :value="$conversation->ai_enabled ? trans('admin::app.cod.conversations.ai-enabled') : trans('admin::app.cod.conversations.ai-disabled')"
        />
    </div>

    @if ($conversation->codOrder)
        <div class="mt-3">
            <x-admin::cod.status-badge :status="$conversation->codOrder->status" />
        </div>
    @endif

    @if (! empty($conversation->answers()))
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.cod.conversations.collected')
            </p>

            <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                @foreach (['name' => 'admin::app.cod.conversations.name', 'city' => 'admin::app.cod.conversations.city', 'address' => 'admin::app.cod.conversations.address', 'phone' => 'admin::app.cod.conversations.phone'] as $field => $labelKey)
                    <div class="flex gap-2">
                        <dt class="shrink-0 text-gray-500 dark:text-gray-400">@lang($labelKey):</dt>
                        <dd class="text-gray-800 dark:text-white">{{ $conversation->answers()[$field] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <!-- Message thread -->
    <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-sm font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.conversations.messages')
        </p>

        @if ($messages->isEmpty())
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.conversations.no-messages')
            </p>
        @else
            <div class="mt-3 grid gap-3">
                @foreach ($messages as $message)
                    @php
                        $isCustomer = $message->direction === 'in';
                        $senderLabel = match ($message->sender) {
                            'ai' => trans('admin::app.cod.conversations.sender-ai'),
                            'human' => trans('admin::app.cod.conversations.sender-human'),
                            'system' => trans('admin::app.cod.conversations.sender-system'),
                            default => trans('admin::app.cod.conversations.sender-customer'),
                        };
                        $bubbleClass = $isCustomer
                            ? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-white'
                            : ($message->sender === 'ai'
                                ? 'bg-blue-50 text-gray-800 ring-1 ring-blue-100 dark:bg-blue-950 dark:text-white dark:ring-blue-900'
                                : ($message->sender === 'human'
                                    ? 'bg-green-50 text-gray-800 ring-1 ring-green-100 dark:bg-green-950 dark:text-white dark:ring-green-900'
                                    : 'bg-gray-50 text-gray-600 ring-1 ring-gray-100 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800'));
                    @endphp

                    <div class="flex {{ $isCustomer ? 'justify-start' : 'justify-end' }}">
                        <div class="max-w-[80%] rounded-lg px-3 py-2 {{ $bubbleClass }}">
                            <p class="text-xs font-semibold opacity-70">
                                {{ $senderLabel }} · {{ $message->created_at?->format('Y-m-d H:i') }}
                            </p>

                            <p class="mt-1 whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>

                            @if ($message->status === 'failed' && $message->error)
                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message->error }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Manual reply -->
    <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-sm font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.conversations.reply')
        </p>

        <form method="POST" action="{{ route('admin.cod.conversations.reply', $conversation) }}" class="mt-3 grid gap-2">
            @csrf

            <textarea
                name="message"
                required
                rows="3"
                maxlength="4000"
                placeholder="@lang('admin::app.cod.conversations.reply-placeholder')"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            ></textarea>

            <div>
                <button type="submit" class="primary-button whitespace-nowrap">
                    @lang('admin::app.cod.conversations.send')
                </button>
            </div>
        </form>
    </div>
</x-admin::layouts>
